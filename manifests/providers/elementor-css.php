<?php
namespace Duo\Providers;

use Duo\ManifestProviderRuntime;
use Duo\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    require_once __DIR__ . '/../../agent/src/Kernel/WpCliChildProcess.php';
}

/**
 * Elementor generated-CSS regeneration provider.
 *
 * Elementor compiles each builder document's styles into its own CSS cache.
 * Duo writes `_elementor_data` with SQL, so Elementor's save path — which is
 * what normally recompiles that CSS — never runs, and a promoted page renders
 * with the SOURCE environment's URLs baked into stale CSS (the exact symptom
 * sandbox/conformance/checks/elementor.sh exists to catch).
 *
 * This provider deliberately invokes Elementor's own documented CLI command,
 * `elementor flush-css --regenerate`, rather than reproducing the regeneration
 * loop in adapter code. That is the boundary doctrine's point: the executable
 * semantics stay the plugin's, because a Duo-side reimplementation of
 * Elementor's compile-and-write pipeline would be plugin business logic Duo
 * would then own forever and have to track across Elementor releases. What
 * this provider adds is identity, structured invocation, and receipts.
 */
final class ElementorCss extends ManifestProviderRuntime {
    private const COMMAND = 'elementor flush-css --regenerate';

    private const REQUIRED_COLUMNS = [
        'posts' => ['ID', 'post_status'],
        'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
    ];

    /**
     * Elementor's per-document RENDER caches: `_elementor_element_cache` is
     * the cached rendered widget HTML, `_elementor_page_assets` the derived
     * asset list. Both are `derived` in manifests/elementor.json and are
     * regenerated lazily on the next front-end render — but only if they are
     * ABSENT. Duo's apply writes `_elementor_data` with raw SQL (see
     * ApplyFieldMaterializer::upsert_meta), so Elementor's own updated_post_
     * meta/save_post hooks — which normally invalidate these on an edit —
     * never fire, and `flush-css --regenerate` re-renders documents to rebuild
     * CSS and can leave the element HTML cache repopulated. grind_adoption A6
     * caught the result: after a release the builder page served its PRE-apply
     * heading for the full cache TTL (~2 min) even though `_elementor_data`
     * already carried the new one. Clearing these keys after regeneration
     * forces the front-end to re-render from the applied data immediately.
     */
    private const RENDER_CACHE_META_KEYS = ['_elementor_element_cache', '_elementor_page_assets'];

    /**
     * Run Elementor's flush-css and prove the CSS cache actually moved.
     *
     * Verification is the CSS FILE INVENTORY, not `_elementor_css` postmeta,
     * and that choice is evidence-driven: manifests/elementor.json's own note
     * records — reproduced independently twice — that `_elementor_css` is
     * created lazily on the first FRONT-END RENDER, explicitly "not just
     * wp-cli flush-css". Requiring that meta after this call would therefore
     * hard-fail every apply on a site nobody has browsed yet. The generated
     * CSS files under uploads are what flush-css owns, and what the
     * conformance check reads back.
     *
     * The verification mirrors Elementor 4.0.0 and 4.2.3's own
     * Files_Manager::generate_css() query and Post CSS receipt: every
     * published post carrying the builder marker must finish with an
     * `_elementor_css.status` of `file` or `empty`; `file` requires exactly
     * its post-<id>.css and `empty` requires no such file. Atomic-only
     * documents with no per-page style rules legitimately use `empty`, so a
     * blanket one-file-per-document predicate would reject valid Elementor
     * 4 pages. Orphan post CSS is independently forbidden.
     *
     * @return array{before:array, after:array, verified:true}
     */
    protected function invoke_regenerate_css(array $args): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                "duo: Elementor CSS regeneration runs the plugin's own '" . self::COMMAND
                . "' command and is unavailable outside wp-cli"
            );
        }
        $beforeDetail = $this->projection_detail();
        $before = $this->projection_summary($beforeDetail, false);

        try {
            $result = WpCliChildProcess::capture(self::COMMAND, 600, 524288, 131072);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND . "' could not start",
                0,
                $t
            );
        }
        if ($result['return_code'] !== 0) {
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND . "' exited {$result['return_code']}"
            );
        }
        $out = trim($result['stdout']);
        $err = trim($result['stderr']);
        if ($err !== '') {
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND
                . "' emitted stderr despite exit 0; recovery_required"
            );
        }
        if (!str_contains($out, 'Success: Flushed the Elementor CSS Cache')) {
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND
                . "' exited 0 without its native success receipt; recovery_required"
            );
        }

        // WpCliChildProcess regenerates in a bounded fresh process.
        // projection_detail() populated this parent's post-meta cache before
        // launch, so readback would otherwise see the pre-command
        // `_elementor_css` receipt after the child committed the new one.
        $this->clear_css_receipt_caches($beforeDetail['builder_document_ids']);

        // Invalidate the render caches AFTER flush-css: `--regenerate`
        // re-renders each document to rebuild CSS and can leave the element
        // HTML cache repopulated (grind_adoption A6). Deleting the keys here
        // is the convergence boundary — the next front-end render rebuilds
        // them from the just-applied `_elementor_data`.
        $this->clear_render_caches();

        $afterDetail = $this->projection_detail();
        if ($beforeDetail['builder_document_ids'] !== $afterDetail['builder_document_ids']) {
            throw new \RuntimeException(
                'duo: Elementor builder-document population changed during CSS regeneration; recovery_required'
            );
        }
        $after = $this->projection_summary($afterDetail, true);
        $after['outcome'] = $before['css_fingerprint'] === $after['css_fingerprint']
            ? 'already-converged'
            : 'regenerated';

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array<string,mixed> */
    protected function reconcile_regenerate_css(array $args): array {
        return $this->projection_summary($this->projection_detail(), true);
    }

    /**
     * @return array{
     *   builder_document_ids:list<int>,
     *   builder_css_statuses:array<int,string>,
     *   css_files:array<string,array{bytes:int,mtime:int,sha256:string}>,
     *   post_css_ids:list<int>,
     *   render_caches:int
     * }
     */
    private function projection_detail(): array {
        $this->assert_schema();
        $documents = $this->builder_document_ids();
        $statuses = $this->builder_css_statuses($documents);
        $inventory = $this->css_inventory();
        $postCssIds = [];
        foreach (array_keys($inventory) as $file) {
            if (preg_match('/^post-([1-9][0-9]*)\.css$/D', $file, $match) === 1) {
                $postCssIds[] = (int) $match[1];
            }
        }
        sort($postCssIds, SORT_NUMERIC);
        return [
            'builder_document_ids' => $documents,
            'builder_css_statuses' => $statuses,
            'css_files' => $inventory,
            'post_css_ids' => $postCssIds,
            'render_caches' => $this->render_cache_count(),
        ];
    }

    /** @param array<string,mixed> $detail @return array<string,int|string> */
    private function projection_summary(array $detail, bool $verify): array {
        $documents = $detail['builder_document_ids'];
        $statuses = $detail['builder_css_statuses'];
        $postCssIds = $detail['post_css_ids'];
        $fileIds = [];
        $emptyIds = [];
        $invalidReceipts = 0;
        foreach ($documents as $id) {
            if (($statuses[$id] ?? null) === 'file') {
                $fileIds[] = $id;
            } elseif (($statuses[$id] ?? null) === 'empty') {
                $emptyIds[] = $id;
            } else {
                $invalidReceipts++;
            }
        }
        $missing = array_values(array_diff($fileIds, $postCssIds));
        $unexpected = array_values(array_intersect($emptyIds, $postCssIds));
        $orphaned = array_values(array_diff($postCssIds, $documents));
        $summary = [
            'builder_documents' => count($documents),
            'css_receipt_files' => count($fileIds),
            'css_receipt_empty' => count($emptyIds),
            'invalid_css_receipts' => $invalidReceipts,
            'css_files' => count($detail['css_files']),
            'post_css_files' => count($postCssIds),
            'css_fingerprint' => hash('sha256', serialize($detail['css_files'])),
            'missing_document_css' => count($missing),
            'unexpected_empty_document_css' => count($unexpected),
            'orphan_document_css' => count($orphaned),
            'render_caches' => $detail['render_caches'],
        ];
        if ($verify) {
            foreach ([
                'invalid_css_receipts',
                'missing_document_css',
                'unexpected_empty_document_css',
                'orphan_document_css',
                'render_caches',
            ] as $field) {
                if ($summary[$field] !== 0) {
                    throw new \RuntimeException(
                        "duo: Elementor CSS readback found {$summary[$field]} $field; recovery_required"
                    );
                }
            }
        }
        return $summary;
    }

    /** @param list<int> $documents @return array<int,string> */
    private function builder_css_statuses(array $documents): array {
        if (!function_exists('get_post_meta')) {
            throw new \RuntimeException(
                'duo: Elementor CSS verification requires get_post_meta()'
            );
        }
        $statuses = [];
        foreach ($documents as $id) {
            $receipt = get_post_meta($id, '_elementor_css', true);
            $status = is_array($receipt) ? ($receipt['status'] ?? null) : null;
            $statuses[$id] = is_string($status) ? $status : 'missing';
        }
        return $statuses;
    }

    /** @param list<int> $documents */
    private function clear_css_receipt_caches(array $documents): void {
        if (!function_exists('wp_cache_delete')) {
            throw new \RuntimeException(
                'duo: Elementor CSS verification requires wp_cache_delete()'
            );
        }
        foreach ($documents as $id) {
            wp_cache_delete($id, 'post_meta');
        }
    }

    private function assert_schema(): void {
        global $wpdb;
        foreach (self::REQUIRED_COLUMNS as $property => $required) {
            $table = (string) ($wpdb->{$property} ?? '');
            if ($table === '') {
                throw new \RuntimeException("duo: Elementor $property table is unavailable; recovery_required");
            }
            $wpdb->last_error = '';
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
            if ((string) ($wpdb->last_error ?? '') !== '' || !is_array($columns)) {
                throw new \RuntimeException("duo: Elementor $property schema probe failed; recovery_required");
            }
            $missing = array_values(array_diff($required, array_map('strval', $columns)));
            if ($missing !== []) {
                throw new \RuntimeException(
                    "duo: Elementor $property table is missing required column(s): "
                    . implode(', ', $missing) . '; recovery_required'
                );
            }
        }
    }

    /**
     * Count Elementor's per-document render caches (a checked read, like
     * builder_document_count()). Recorded before/after regeneration so the
     * receipt proves the rendered-HTML cache no longer predates the applied
     * `_elementor_data`.
     */
    private function render_cache_count(): int {
        global $wpdb;
        $wpdb->last_error = '';
        $in = "'" . implode("','", array_map('esc_sql', self::RENDER_CACHE_META_KEYS)) . "'";
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ($in)");
        if ($count === null || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('duo: Elementor render-cache count query failed');
        }
        return (int) $count;
    }

    /**
     * Delete Elementor's per-document render caches so the next front-end
     * render rebuilds them from the applied `_elementor_data`. Uses the WP
     * API (which also drops the object-cache copy), not raw SQL.
     */
    private function clear_render_caches(): void {
        if (!function_exists('delete_post_meta_by_key')) {
            throw new \RuntimeException(
                'duo: Elementor render-cache invalidation requires delete_post_meta_by_key()'
            );
        }
        foreach (self::RENDER_CACHE_META_KEYS as $key) {
            delete_post_meta_by_key($key);
        }
    }

    /**
     * Fingerprint of Elementor's generated CSS directory: file name => size,
     * modification time and content hash. Compared as a whole rather than by
     * count, so a regeneration that rewrites the same file names is visible.
     *
     * @return array<string, array{bytes:int, mtime:int, sha256:string}>
     */
    private function css_inventory(): array {
        if (!function_exists('wp_upload_dir')) {
            throw new \RuntimeException(
                'duo: Elementor CSS verification requires wp_upload_dir()'
            );
        }
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || (string) ($uploads['error'] ?? '') !== '') {
            throw new \RuntimeException(
                'duo: Elementor CSS verification could not resolve the uploads base directory'
            );
        }
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/');
        if ($base === '') {
            throw new \RuntimeException(
                'duo: Elementor CSS verification could not resolve the uploads base directory'
            );
        }
        $dir = $base . '/elementor/css';
        if (!is_dir($dir)) {
            return [];
        }
        if (is_link($dir)) {
            throw new \RuntimeException('duo: Elementor CSS directory is a symbolic link; recovery_required');
        }
        $entries = scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("duo: Elementor CSS directory $dir is unreadable");
        }
        $out = [];
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_link($path) || !is_file($path)) {
                throw new \RuntimeException(
                    "duo: Elementor CSS inventory contains unsupported entry $entry; recovery_required"
                );
            }
            $size = filesize($path);
            $mtime = filemtime($path);
            $sha256 = hash_file('sha256', $path);
            if ($size === false || $mtime === false || $sha256 === false) {
                throw new \RuntimeException("duo: Elementor CSS file $entry is unreadable");
            }
            $out[$entry] = [
                'bytes' => (int) $size,
                'mtime' => (int) $mtime,
                'sha256' => $sha256,
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * How many posts Elementor considers builder documents. Recorded in the
     * receipt as the population the regeneration covered; a checked read,
     * because a failed query returning an empty-looking value would otherwise
     * make a receipt claim a smaller population than the site really has.
     */
    /** @return list<int> */
    private function builder_document_ids(): array {
        global $wpdb;
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID "
            . "WHERE p.post_status = 'publish' AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY p.ID",
            '_elementor_edit_mode',
            'builder'
        ));
        if (!is_array($ids) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                'duo: Elementor builder-document inventory query failed; recovery_required'
            );
        }
        return array_values(array_map('intval', $ids));
    }
}
