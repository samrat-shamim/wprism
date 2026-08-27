<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';

    $scratch = sys_get_temp_dir() . '/duo_elementor_css_provider_' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
        throw new \RuntimeException("could not create $scratch");
    }

    $GLOBALS['ec_uploads'] = ['basedir' => $scratch, 'error' => false];
    $GLOBALS['ec_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => "Success: Flushed the Elementor CSS Cache.\n",
        'stderr' => '',
    ];
    $GLOBALS['ec_command_throw'] = null;
    $GLOBALS['ec_command_calls'] = [];
    $GLOBALS['ec_after_command'] = null;
    $GLOBALS['ec_delete_calls'] = [];
    $GLOBALS['ec_retain_render_caches'] = false;
    $GLOBALS['ec_css_statuses'] = [11 => 'file', 22 => 'file'];
    $GLOBALS['ec_css_status_cache'] = [];
    $GLOBALS['ec_cache_delete_calls'] = [];

    function wp_upload_dir(): mixed {
        return $GLOBALS['ec_uploads'];
    }

    function esc_sql(string $value): string {
        return str_replace("'", "''", $value);
    }

    function delete_post_meta_by_key(string $key): bool {
        $GLOBALS['ec_delete_calls'][] = $key;
        if (!$GLOBALS['ec_retain_render_caches']) {
            $GLOBALS['wpdb']->renderCaches = 0;
        }
        return true;
    }

    function get_post_meta(int $postId, string $key, bool $single = false): mixed {
        if ($key !== '_elementor_css') {
            return $single ? '' : [];
        }
        if (!array_key_exists($postId, $GLOBALS['ec_css_status_cache'])) {
            $GLOBALS['ec_css_status_cache'][$postId] = $GLOBALS['ec_css_statuses'][$postId] ?? null;
        }
        $status = $GLOBALS['ec_css_status_cache'][$postId];
        $value = is_string($status) ? ['status' => $status] : '';
        return $single ? $value : [$value];
    }

    function wp_cache_delete(int $key, string $group = ''): bool {
        $GLOBALS['ec_cache_delete_calls'][] = [$key, $group];
        if ($group === 'post_meta') {
            unset($GLOBALS['ec_css_status_cache'][$key]);
        }
        return true;
    }

    final class WP_CLI {
        use \DuoTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['ec_command_calls'][] = [$command, $options];
            if ($GLOBALS['ec_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['ec_command_throw'];
            }
            if (is_callable($GLOBALS['ec_after_command'])) {
                ($GLOBALS['ec_after_command'])();
            }
            return $GLOBALS['ec_command_result'];
        }
    }

    final class ElementorCssWpdb {
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        /** @var array<string,list<string>> */
        public array $columns = [
            'wp_posts' => ['ID', 'post_status'],
            'wp_postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
        ];
        /** @var list<int> */
        public array $builderIds = [11, 22];
        /** @var list<list<int>> */
        public array $builderIdSequence = [];
        public int $renderCaches = 0;
        public ?string $failSchemaTable = null;
        public bool $failBuilderQuery = false;
        public bool $failRenderQuery = false;
        public int $builderQueries = 0;

        public function prepare(string $query, mixed ...$args): string {
            foreach ($args as $arg) {
                $replacement = "'" . str_replace("'", "''", (string) $arg) . "'";
                $query = preg_replace('/%s/', $replacement, $query, 1) ?? $query;
            }
            return $query;
        }

        public function get_col(string $query): array|false {
            if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $query, $match) === 1) {
                if ($this->failSchemaTable === $match[1]) {
                    $this->last_error = 'fixture schema secret must not escape';
                    return false;
                }
                $this->last_error = '';
                return $this->columns[$match[1]] ?? [];
            }
            if (str_contains($query, 'SELECT DISTINCT p.ID')) {
                $this->builderQueries++;
                if ($this->failBuilderQuery) {
                    $this->last_error = 'fixture builder query secret must not escape';
                    return false;
                }
                $this->last_error = '';
                if ($this->builderIdSequence !== []) {
                    return array_shift($this->builderIdSequence);
                }
                return $this->builderIds;
            }
            $this->last_error = 'fixture unexpected column query';
            return false;
        }

        public function get_var(string $query): int|null {
            if (!str_contains($query, '_elementor_element_cache')
                || !str_contains($query, '_elementor_page_assets')) {
                $this->last_error = 'fixture unexpected scalar query';
                return null;
            }
            if ($this->failRenderQuery) {
                $this->last_error = 'fixture render query secret must not escape';
                return null;
            }
            $this->last_error = '';
            return $this->renderCaches;
        }
    }

    function ec_remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            ec_remove_tree($entry->getPathname());
        }
        rmdir($path);
    }

    function ec_css_dir(): string {
        return (string) $GLOBALS['ec_uploads']['basedir'] . '/elementor/css';
    }

    /** @param array<string,string> $files */
    function ec_write_css(array $files): void {
        $directory = ec_css_dir();
        ec_remove_tree($directory);
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("could not create $directory");
        }
        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }
    }
}

namespace Duo {
    final class Policy {}

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once dirname(__DIR__, 4) . '/manifests/providers/elementor-css.php';

    use Duo\Providers\ElementorCss;

    function ec_reset(): ElementorCss {
        ec_remove_tree($GLOBALS['ec_scratch'] . '/elementor');
        // Reset the full array so a prior malformed wp_upload_dir() result
        // cannot contaminate the next hostile case.
        $GLOBALS['ec_uploads'] = ['basedir' => $GLOBALS['ec_scratch'], 'error' => false];
        $GLOBALS['ec_command_result'] = (object) [
            'return_code' => 0,
            'stdout' => "Success: Flushed the Elementor CSS Cache.\n",
            'stderr' => '',
        ];
        $GLOBALS['ec_command_throw'] = null;
        $GLOBALS['ec_command_calls'] = [];
        $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = false;
        $GLOBALS['ec_after_command'] = null;
        $GLOBALS['ec_delete_calls'] = [];
        $GLOBALS['ec_retain_render_caches'] = false;
        $GLOBALS['ec_css_statuses'] = [11 => 'file', 22 => 'file'];
        $GLOBALS['ec_css_status_cache'] = [];
        $GLOBALS['ec_cache_delete_calls'] = [];
        $GLOBALS['wpdb'] = new ElementorCssWpdb();
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/elementor.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return new ElementorCss($manifest['providers'][0]);
    }

    function ec_operation(): array {
        return [
            'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
            'authority_hash' => str_repeat('a', 64),
            'lease_session_id' => 'fixture-session',
            'operation_id' => 'fixture-operation',
            'input_hash' => str_repeat('b', 64),
            'effect_hash' => str_repeat('c', 64),
        ];
    }

    // Preserve the unique root outside ec_reset(), whose job is to restore all
    // mutable WordPress/plugin state between hostile cases.
    $GLOBALS['ec_scratch'] = $scratch;

    $provider = ec_reset();
    duo_check_same(
        ['id' => 'elementor-css', 'plugin' => 'elementor/elementor.php', 'version' => '2.0.0'],
        $provider->identity(),
        'provider identity makes the strengthened Elementor CSS contract fleet-visible'
    );
    duo_check_same(
        [
            'args' => [],
            'reads' => ['table:posts', 'table:postmeta'],
            'writes' => ['table:postmeta', 'entity:elementor-generated-css'],
            'scope' => 'site',
            'idempotent' => true,
            'timeout_seconds' => 600,
            'scoped' => [
                'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                'reconcile' => true,
            ],
        ],
        $provider->capabilities()['regenerate_css'] ?? null,
        'provider declares every read, write, scope, timeout and recovery property'
    );
    duo_check_throws(
        static fn(): array => $provider->invoke('unknown', []),
        \RuntimeException::class,
        'unknown full invocation capability refuses',
        'does not implement capability'
    );
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('unknown', [], ec_operation()),
        \RuntimeException::class,
        'unknown recovery capability refuses',
        'does not implement capability'
    );

    $provider = ec_reset();
    $GLOBALS['wpdb']->renderCaches = 4;
    ec_write_css([
        'post-11.css' => 'stale-source-url https://source.invalid',
        'post-999.css' => 'orphan-target-css',
    ]);
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css([
            'post-11.css' => 'regenerated-eleven',
            'post-22.css' => 'regenerated-twenty-two',
        ]);
    };
    $receipt = $provider->invoke_scoped('regenerate_css', [], ec_operation());
    duo_check_same(true, $receipt['verified'] ?? null, 'full scoped invocation verifies its postcondition');
    duo_check_same(ec_operation(), $receipt['operation'] ?? null, 'full scoped invocation binds the caller operation');
    duo_check_same(2, $receipt['before']['builder_documents'] ?? null, 'receipt bounds the builder population before mutation');
    duo_check_same(2, $receipt['after']['builder_documents'] ?? null, 'receipt bounds the builder population after mutation');
    duo_check_same(2, $receipt['after']['css_files'] ?? null, 'receipt counts the exact flat CSS inventory');
    duo_check_same(2, $receipt['after']['post_css_files'] ?? null, 'receipt counts the exact per-document CSS projection');
    duo_check_same(2, $receipt['after']['css_receipt_files'] ?? null, 'receipt counts native file-producing documents');
    duo_check_same(0, $receipt['after']['css_receipt_empty'] ?? null, 'receipt counts native empty documents independently');
    duo_check_same(0, $receipt['after']['invalid_css_receipts'] ?? null, 'verified receipt has a native status for every builder document');
    duo_check_same(0, $receipt['after']['missing_document_css'] ?? null, 'verified receipt has no silently skipped builder document');
    duo_check_same(0, $receipt['after']['unexpected_empty_document_css'] ?? null, 'verified receipt has no CSS for a native empty document');
    duo_check_same(0, $receipt['after']['orphan_document_css'] ?? null, 'verified receipt has no deleted-document CSS residue');
    duo_check_same(0, $receipt['after']['render_caches'] ?? null, 'verified receipt proves stale rendered HTML caches absent');
    duo_check_same('regenerated', $receipt['after']['outcome'] ?? null, 'changed CSS bytes report a regenerated outcome');
    duo_check(
        preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['css_fingerprint'] ?? '')) === 1,
        'receipt publishes a bounded fingerprint instead of stylesheet contents'
    );
    duo_check_same(1, count($GLOBALS['ec_command_calls']), 'provider invokes one bounded fresh process');
    [$elementorCommand, $elementorOptions] = $GLOBALS['ec_command_calls'][0];
    duo_check(
        str_starts_with($elementorCommand, 'exec ')
            && str_contains($elementorCommand, 'elementor flush-css --regenerate'),
        'bounded launch preserves the exact plugin-owned command'
    );
    duo_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $elementorOptions,
        'the fake command boundary observes the isolated launch contract'
    );
    duo_check_same(
        ['_elementor_element_cache', '_elementor_page_assets'],
        $GLOBALS['ec_delete_calls'],
        'provider invalidates both known rendered-document caches after native regeneration'
    );
    duo_check_same(
        [[11, 'post_meta'], [22, 'post_meta']],
        $GLOBALS['ec_cache_delete_calls'],
        'provider expires parent-process receipt caches after child-process regeneration'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($published)
            && !str_contains($published, 'source.invalid')
            && !str_contains($published, 'regenerated-eleven'),
        'provider receipt excludes authored CSS bytes and environment URLs'
    );
    duo_check(
        is_file(ec_css_dir() . '/post-11.css')
            && is_file(ec_css_dir() . '/post-22.css')
            && !is_file(ec_css_dir() . '/post-999.css'),
        'native regeneration replaces stale CSS and removes orphaned document output'
    );

    $reconciled = $provider->reconcile_scoped('regenerate_css', [], ec_operation());
    duo_check_same(true, $reconciled['verified'] ?? null, 'scoped recovery executes the real readback path');
    duo_check_same(0, $reconciled['after']['missing_document_css'] ?? null, 'scoped recovery proves document CSS completeness');
    duo_check_same(0, $reconciled['after']['orphan_document_css'] ?? null, 'scoped recovery proves deleted CSS absence');
    duo_check_same(0, $reconciled['after']['render_caches'] ?? null, 'scoped recovery proves rendered caches absent');

    $GLOBALS['ec_after_command'] = static function (): void {};
    $idempotent = $provider->invoke('regenerate_css', []);
    duo_check_same('already-converged', $idempotent['after']['outcome'] ?? null, 'identical complete native output is a valid idempotent retry');
    duo_check_same(
        $idempotent['before']['css_fingerprint'] ?? null,
        $idempotent['after']['css_fingerprint'] ?? null,
        'idempotent retry preserves the full CSS inventory fingerprint'
    );

    $provider = ec_reset();
    unset($GLOBALS['ec_css_statuses'][22]);
    ec_write_css(['post-11.css' => 'before']);
    $GLOBALS['ec_after_command'] = static function (): void {
        $GLOBALS['ec_css_statuses'][22] = 'file';
        ec_write_css(['post-11.css' => 'after', 'post-22.css' => 'after']);
    };
    $crossProcess = $provider->invoke('regenerate_css', []);
    duo_check_same(
        0,
        $crossProcess['after']['invalid_css_receipts'] ?? null,
        'postcondition evicts a missing pre-command receipt cached in the parent process'
    );
    duo_check_same(
        2,
        $crossProcess['after']['css_receipt_files'] ?? null,
        'postcondition reads both receipts committed by the child process'
    );

    $provider = ec_reset();
    $GLOBALS['ec_css_statuses'][22] = 'empty';
    ec_write_css(['post-11.css' => 'before']);
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after']);
    };
    $emptyReceipt = $provider->invoke('regenerate_css', []);
    duo_check_same(1, $emptyReceipt['after']['css_receipt_files'] ?? null, 'native file receipt requires one generated stylesheet');
    duo_check_same(1, $emptyReceipt['after']['css_receipt_empty'] ?? null, 'Atomic-style empty receipt is represented explicitly');
    duo_check_same(1, $emptyReceipt['after']['post_css_files'] ?? null, 'legitimate empty document needs no synthetic stylesheet');
    duo_check_same(0, $emptyReceipt['after']['missing_document_css'] ?? null, 'legitimate empty document is not misreported as missing CSS');

    $provider = ec_reset();
    unset($GLOBALS['ec_css_statuses'][22]);
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after']);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'missing native CSS receipt refuses instead of inferring completion from file absence',
        'found 1 invalid_css_receipts'
    );

    $provider = ec_reset();
    $GLOBALS['ec_css_statuses'][22] = 'empty';
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after', 'post-22.css' => 'stale']);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'native empty receipt with retained stylesheet refuses',
        'found 1 unexpected_empty_document_css'
    );

    $provider = ec_reset();
    ec_write_css(['post-11.css' => 'before']);
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after']);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'native success that skips one builder document refuses',
        'found 1 missing_document_css'
    );

    $provider = ec_reset();
    ec_write_css(['post-11.css' => 'before', 'post-22.css' => 'before']);
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css([
            'post-11.css' => 'after',
            'post-22.css' => 'after',
            'post-999.css' => 'orphan',
        ]);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'native success that retains deleted-document CSS refuses',
        'found 1 orphan_document_css'
    );

    $provider = ec_reset();
    $GLOBALS['wpdb']->renderCaches = 3;
    $GLOBALS['ec_retain_render_caches'] = true;
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after', 'post-22.css' => 'after']);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'native success with retained rendered HTML caches refuses',
        'found 3 render_caches'
    );

    $provider = ec_reset();
    $GLOBALS['wpdb']->builderIdSequence = [[11, 22], [11, 22, 33]];
    $GLOBALS['ec_after_command'] = static function (): void {
        ec_write_css(['post-11.css' => 'after', 'post-22.css' => 'after']);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'builder population race refuses rather than certifying a stale projection',
        'population changed during CSS regeneration'
    );

    $provider = ec_reset();
    $GLOBALS['wpdb']->columns['wp_postmeta'] = ['post_id', 'meta_key', 'meta_value'];
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'missing required schema column refuses before native mutation',
        'missing required column(s): meta_id'
    );
    duo_check_same([], $GLOBALS['ec_command_calls'], 'schema refusal occurs before the native command boundary');

    $provider = ec_reset();
    $GLOBALS['wpdb']->failSchemaTable = 'wp_posts';
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'schema query failure refuses with redacted database detail');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Elementor posts schema probe failed; recovery_required'
                && !str_contains($e->getMessage(), 'fixture schema secret'),
            'schema query failure refuses with redacted database detail'
        );
    }

    $provider = ec_reset();
    $GLOBALS['wpdb']->failBuilderQuery = true;
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'builder inventory query failure refuses with redacted database detail');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Elementor builder-document inventory query failed; recovery_required'
                && !str_contains($e->getMessage(), 'fixture builder query secret'),
            'builder inventory query failure refuses with redacted database detail'
        );
    }

    $provider = ec_reset();
    $GLOBALS['wpdb']->failRenderQuery = true;
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'render-cache query failure refuses with redacted database detail');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Elementor render-cache count query failed'
                && !str_contains($e->getMessage(), 'fixture render query secret'),
            'render-cache query failure refuses with redacted database detail'
        );
    }

    $provider = ec_reset();
    $GLOBALS['ec_command_throw'] = new \RuntimeException('fixture launch secret');
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'thrown native launch failure is wrapped at the command boundary');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === "duo: Elementor 'elementor flush-css --regenerate' could not start"
                && $e->getPrevious()?->getMessage() === 'duo: bounded WP-CLI child could not start'
                && $e->getPrevious()?->getPrevious()?->getMessage() === 'fixture launch secret',
            'thrown native launch failure is wrapped at the command boundary'
        );
    }

    foreach ([null, (object) ['return_code' => '0', 'stdout' => 'Success: Flushed the Elementor CSS Cache']] as $result) {
        $provider = ec_reset();
        $GLOBALS['ec_command_result'] = $result;
        duo_check_throws(
            static fn(): array => $provider->invoke('regenerate_css', []),
            \RuntimeException::class,
            'malformed native process result refuses instead of coercing success',
            'could not start'
        );
    }

    $provider = ec_reset();
    $GLOBALS['ec_command_result'] = (object) [
        'return_code' => 255,
        'stdout' => 'native stdout context',
        'stderr' => 'native stderr context',
    ];
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'nonzero native exit refuses and retains actionable command context');
    } catch (\RuntimeException $e) {
        duo_check(
            str_contains($e->getMessage(), 'exited 255')
                && !str_contains($e->getMessage(), 'native stdout context')
                && !str_contains($e->getMessage(), 'native stderr context'),
            'nonzero native exit refuses with command context while child output stays private'
        );
    }

    $provider = ec_reset();
    $GLOBALS['ec_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => 'Success: Flushed the Elementor CSS Cache',
        'stderr' => str_repeat('fixture zero-exit secret', 5000),
    ];
    $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = true;
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'exit-zero stderr refuses without publishing stderr bytes');
    } catch (\RuntimeException $e) {
        duo_check(
            str_contains($e->getMessage(), 'emitted stderr despite exit 0')
                && !str_contains($e->getMessage(), 'fixture zero-exit secret'),
            'exit-zero stderr refuses without publishing stderr bytes'
        );
    }

    $provider = ec_reset();
    $GLOBALS['ec_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => str_repeat('credential-shaped-boot-output-', 20000),
        'stderr' => '',
    ];
    $overflowMessage = '';
    try {
        $provider->invoke('regenerate_css', []);
    } catch (RuntimeException $failure) {
        $overflowMessage = $failure->getMessage();
    }
    duo_check(
        str_contains($overflowMessage, "Elementor 'elementor flush-css --regenerate' could not start")
            && !str_contains($overflowMessage, 'credential-shaped')
            && $GLOBALS['ec_delete_calls'] === []
            && $GLOBALS['ec_cache_delete_calls'] === [],
        'Elementor wraps helper overflow without output leak or post-command cache continuation'
    );

    $provider = ec_reset();
    $GLOBALS['ec_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => 'Success: unrelated command finished',
        'stderr' => '',
    ];
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'exit zero without Elementor native success receipt refuses',
        'without its native success receipt'
    );

    $provider = ec_reset();
    $GLOBALS['ec_uploads'] = ['basedir' => '', 'error' => false];
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'missing uploads base refuses before native mutation',
        'could not resolve the uploads base directory'
    );

    $provider = ec_reset();
    $GLOBALS['ec_uploads'] = ['basedir' => $scratch, 'error' => 'filesystem credentials required'];
    try {
        $provider->invoke('regenerate_css', []);
        duo_check(false, 'WordPress uploads error refuses without publishing environment detail');
    } catch (\RuntimeException $e) {
        duo_check(
            str_contains($e->getMessage(), 'could not resolve the uploads base directory')
                && !str_contains($e->getMessage(), 'filesystem credentials'),
            'WordPress uploads error refuses without publishing environment detail'
        );
    }

    $provider = ec_reset();
    $outside = $scratch . '/outside-css';
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/sentinel', 'preserve');
    mkdir(dirname(ec_css_dir()), 0700, true);
    symlink($outside, ec_css_dir());
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'symlinked CSS directory refuses before command execution',
        'CSS directory is a symbolic link'
    );
    duo_check_same('preserve', file_get_contents($outside . '/sentinel'), 'symlink refusal preserves the outside target');
    duo_check_same([], $GLOBALS['ec_command_calls'], 'symlink refusal occurs before native mutation');
    unlink(ec_css_dir());
    ec_remove_tree($outside);

    $provider = ec_reset();
    mkdir(ec_css_dir() . '/nested', 0700, true);
    duo_check_throws(
        static fn(): array => $provider->invoke('regenerate_css', []),
        \RuntimeException::class,
        'unsupported nested CSS inventory refuses instead of silently omitting state',
        'contains unsupported entry nested'
    );

    $provider = ec_reset();
    $GLOBALS['wpdb']->builderIds = [];
    $GLOBALS['ec_after_command'] = static function (): void {};
    $empty = $provider->invoke('regenerate_css', []);
    duo_check_same(0, $empty['after']['builder_documents'] ?? null, 'empty builder population is represented exactly');
    duo_check_same(0, $empty['after']['css_files'] ?? null, 'empty CSS projection is represented exactly');
    duo_check_same('already-converged', $empty['after']['outcome'] ?? null, 'empty site is a valid idempotent convergence state');

    ec_remove_tree($scratch);
    duo_check_summary('Elementor CSS provider');
}
