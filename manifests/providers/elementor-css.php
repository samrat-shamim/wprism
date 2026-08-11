<?php
namespace Duo\Providers;

use Duo\Policy;

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
final class ElementorCss {
    private Policy $policy;

    private const COMMAND = 'elementor flush-css --regenerate';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'elementor-css',
            'plugin' => 'elementor/elementor.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * 600 seconds: regeneration is per-builder-document and compiles real
     * stylesheets, so on a large site this is genuinely the slowest thing in
     * the rebuild pass. The budget is a bound on what the receipt may claim,
     * not a promise of preemption (Providers::invoke()'s own docblock is
     * explicit about that limit).
     */
    public function capabilities(): array {
        return [
            'regenerate_css' => [
                'args' => [],
                'reads' => ['table:postmeta'],
                'writes' => ['table:postmeta', 'entity:elementor-generated-css'],
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
            'regenerate_css' => $this->regenerate_css(),
            default => throw new \RuntimeException(
                "duo: Elementor CSS provider does not implement capability '$capability'"
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
        if ($capability !== 'regenerate_css') {
            throw new \RuntimeException(
                "duo: Elementor CSS provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->scoped_postcondition(),
            'verified' => true,
        ];
    }

    /** @return array{builder_documents:int,css_files:array<string,array{bytes:int,mtime:int}>} */
    private function scoped_postcondition(): array {
        return [
            'builder_documents' => $this->builder_document_count(),
            'css_files' => $this->css_inventory(),
        ];
    }

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
     * The predicate: a completed flush-css either rewrites or removes cached
     * CSS, so the inventory must differ across the call whenever there was
     * anything cached. An unchanged non-empty inventory means the command
     * exited 0 having done nothing — precisely the false-green DUO-3282
     * showed a bare exit code cannot distinguish. An empty-to-empty
     * transition is a legitimate no-op (nothing has been rendered yet) and is
     * recorded as such rather than dressed up as a regeneration.
     *
     * @return array{before:array, after:array, verified:true}
     */
    private function regenerate_css(): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                "duo: Elementor CSS regeneration runs the plugin's own '" . self::COMMAND
                . "' command and is unavailable outside wp-cli"
            );
        }
        $before = [
            'builder_documents' => $this->builder_document_count(),
            'css_files' => $this->css_inventory(),
        ];

        $result = \WP_CLI::runcommand(self::COMMAND, [
            'launch' => true,
            'return' => 'all',
            'exit_error' => false,
        ]);
        if ((int) $result->return_code !== 0) {
            // DUO-3282: the launch layer is a genuinely separate process
            // boundary, and a bare exit code does not explain a fatal inside
            // the plugin's own command. Surfacing the tails is the difference
            // between "exited 255" and the error message that already
            // existed and was being discarded.
            $out = trim((string) ($result->stdout ?? ''));
            $err = trim((string) ($result->stderr ?? ''));
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND . "' exited {$result->return_code}"
                . ($out !== '' ? "\nstdout: $out" : '')
                . ($err !== '' ? "\nstderr: $err" : '')
            );
        }

        $after = [
            'builder_documents' => $this->builder_document_count(),
            'css_files' => $this->css_inventory(),
        ];
        if ($before['css_files'] !== [] && $before['css_files'] === $after['css_files']) {
            throw new \RuntimeException(
                "duo: Elementor '" . self::COMMAND . "' exited 0 but left "
                . count($after['css_files']) . ' cached CSS file(s) untouched; '
                . 'the generated stylesheets still carry pre-apply state'
            );
        }
        $after['outcome'] = $after['css_files'] === [] && $before['css_files'] === []
            ? 'no-op (no generated CSS cached on this target yet)'
            : 'regenerated';

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /**
     * Fingerprint of Elementor's generated CSS directory: file name => size
     * and modification time. Compared as a whole rather than by count, so a
     * regeneration that rewrites the same file names is still visible.
     *
     * @return array<string, array{bytes:int, mtime:int}>
     */
    private function css_inventory(): array {
        if (!function_exists('wp_upload_dir')) {
            throw new \RuntimeException(
                'duo: Elementor CSS verification requires wp_upload_dir()'
            );
        }
        $uploads = wp_upload_dir();
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
        $entries = scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("duo: Elementor CSS directory $dir is unreadable");
        }
        $out = [];
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if ($entry === '.' || $entry === '..' || !is_file($path)) {
                continue;
            }
            $size = filesize($path);
            $mtime = filemtime($path);
            if ($size === false || $mtime === false) {
                throw new \RuntimeException("duo: Elementor CSS file $path is unreadable");
            }
            $out[$entry] = ['bytes' => (int) $size, 'mtime' => (int) $mtime];
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
    private function builder_document_count(): int {
        global $wpdb;
        $wpdb->last_error = '';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_elementor_edit_mode',
            'builder'
        ));
        if ($count === null || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                'duo: Elementor builder-document count query failed'
            );
        }
        return (int) $count;
    }
}
