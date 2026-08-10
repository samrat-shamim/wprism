<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * Yoast SEO indexable rebuild provider.
 *
 * Yoast maintains its own indexable projection of posts and terms, normally
 * refreshed by the save hooks Duo's direct-SQL apply deliberately skips. This
 * provider is the DUO-3338 port of the `yoast index --reindex
 * --skip-confirmation` declaration manifests/yoast.json previously carried in
 * the retired `rebuilders` channel. It still runs Yoast's own documented CLI
 * command — the indexing semantics stay the plugin's — and adds identity,
 * receipts, and a value-level readback.
 *
 * `--skip-confirmation` is Yoast's OWN flag, not wp-cli's generic `--yes`;
 * manifests/yoast.json records that being confirmed empirically against a
 * running install (`--yes` is rejected as an unknown parameter).
 */
final class YoastIndex {
    private Policy $policy;

    private const COMMAND = 'yoast index --reindex --skip-confirmation';
    private const INDEXABLE_TABLE = 'yoast_indexable';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'yoast-index',
            'plugin' => 'wordpress-seo/wp-seo.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * 600 seconds: a full reindex walks every public post and term, so it is
     * the one capability here whose cost genuinely scales with content
     * volume. As Providers::invoke() documents, the budget bounds what the
     * receipt may claim rather than preempting the work.
     */
    public function capabilities(): array {
        return [
            'reindex' => [
                'args' => [],
                'reads' => ['table:posts', 'table:terms'],
                'writes' => ['table:yoast_indexable', 'table:yoast_indexable_hierarchy'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 600,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        return match ($capability) {
            'reindex' => $this->reindex(),
            default => throw new \RuntimeException(
                "duo: Yoast index provider does not implement capability '$capability'"
            ),
        };
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $this->scoped_postcondition(),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'reindex') {
            throw new \RuntimeException(
                "duo: Yoast index provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->scoped_postcondition(),
            'verified' => true,
        ];
    }

    /** @return array{environment_type:string,public_posts:int,indexables:int,outcome:string} */
    private function scoped_postcondition(): array {
        $environment = function_exists('wp_get_environment_type')
            ? (string) wp_get_environment_type()
            : 'unknown';
        $publicPosts = $this->public_post_count();
        $indexables = $this->indexable_count();
        $indexingEnabled = $environment === 'production';
        if ($indexingEnabled && $publicPosts > 0 && $indexables === 0) {
            throw new \RuntimeException(
                'duo: Yoast indexable readback is empty while public posts exist; recovery_required'
            );
        }
        return [
            'environment_type' => $environment,
            'public_posts' => $publicPosts,
            'indexables' => $indexables,
            'outcome' => $indexingEnabled
                ? 'reindexed'
                : 'no-op (Yoast 15.1+ builds indexables only on a production environment type)',
        ];
    }

    /**
     * Run Yoast's reindex and verify the indexable table where verification
     * is possible at all.
     *
     * The conditional is Yoast's own documented behavior, recorded in
     * manifests/yoast.json's note: since Yoast SEO 15.1, indexable creation is
     * production-only by design, gated on wp_get_environment_type() ===
     * 'production' and bypassable only through Yoast's own
     * should_index_indexables filter. On any other environment the command
     * legitimately indexes nothing, so demanding rows back would hard-fail
     * every non-production apply for doing exactly what the plugin says it
     * will do. That gate is Yoast's call to make per site; this provider
     * reports which branch it took instead of defining the override filter,
     * which would change Yoast's behavior underneath every site including
     * production.
     *
     * Where the gate is open and there is indexable content, the readback is
     * a real value-level proof: a non-zero row count after the run.
     *
     * @return array{before:array, after:array, verified:true}
     */
    private function reindex(): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                "duo: Yoast reindex runs the plugin's own '" . self::COMMAND
                . "' command and is unavailable outside wp-cli"
            );
        }
        $environment = function_exists('wp_get_environment_type')
            ? (string) wp_get_environment_type()
            : 'unknown';
        $before = [
            'environment_type' => $environment,
            'public_posts' => $this->public_post_count(),
            'indexables' => $this->indexable_count(),
        ];

        $result = \WP_CLI::runcommand(self::COMMAND, [
            'launch' => true,
            'return' => 'all',
            'exit_error' => false,
        ]);
        if ((int) $result->return_code !== 0) {
            // DUO-3282: surface the separate process boundary's own output;
            // a bare exit code cannot distinguish a missing sub-command from
            // a fatal inside the plugin's indexing run.
            $out = trim((string) ($result->stdout ?? ''));
            $err = trim((string) ($result->stderr ?? ''));
            throw new \RuntimeException(
                "duo: Yoast '" . self::COMMAND . "' exited {$result->return_code}"
                . ($out !== '' ? "\nstdout: $out" : '')
                . ($err !== '' ? "\nstderr: $err" : '')
            );
        }

        $indexables = $this->indexable_count();
        $indexingEnabled = $environment === 'production';
        if ($indexingEnabled && $before['public_posts'] > 0 && $indexables === 0) {
            throw new \RuntimeException(
                "duo: Yoast '" . self::COMMAND . "' exited 0 but left " . self::INDEXABLE_TABLE
                . " empty while {$before['public_posts']} public post(s) exist; "
                . 'the indexable projection was not rebuilt'
            );
        }

        return [
            'before' => $before,
            'after' => [
                'environment_type' => $environment,
                'public_posts' => $before['public_posts'],
                'indexables' => $indexables,
                'outcome' => $indexingEnabled
                    ? 'reindexed'
                    : 'no-op (Yoast 15.1+ builds indexables only on a production environment type)',
            ],
            'verified' => true,
        ];
    }

    /**
     * Checked count of Yoast's indexable rows. A missing table is a real
     * answer (Yoast has not created it on this install), distinct from a
     * failed query, which must never be read as "zero rows".
     */
    private function indexable_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::INDEXABLE_TABLE;
        $wpdb->last_error = '';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: Yoast indexable table probe failed for $table");
        }
        if ($exists === null) {
            return 0;
        }
        $wpdb->last_error = '';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        if ($count === null || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: Yoast indexable count query failed for $table");
        }
        return (int) $count;
    }

    /**
     * How much indexable content this site actually has. Without it, "zero
     * indexables" is ambiguous between a failed rebuild and an empty site,
     * and the receipt would not say which.
     */
    private function public_post_count(): int {
        global $wpdb;
        $wpdb->last_error = '';
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item')"
        );
        if ($count === null || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('duo: Yoast reindex public-post count query failed');
        }
        return (int) $count;
    }
}
