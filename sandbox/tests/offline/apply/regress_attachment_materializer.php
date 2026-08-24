<?php
declare(strict_types=1);

namespace Duo {
    /** Rollback-only transaction double used by the real metadata generator. */
    final class Db {
        public static int $starts = 0;
        public static int $rollbacks = 0;

        public static function start_repeatable_read(string $purpose): void { ++self::$starts; }
        public static function rollback(string $purpose): void { ++self::$rollbacks; }
    }
}

namespace {
    /**
     * Product-path regression for the durable attachment filesystem/native
     * metadata boundary. The former extraction-only suite could stay green
     * while originals published before COMMIT, prior bytes lived below the
     * web-served uploads tree, prefix-neighbor files were deleted, native
     * filters ran, and every crash phase wedged the next apply.
     */
    final class WP_Hook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }
    final class WP_Image_Editor_GD {}

    $GLOBALS['wp_filter'] = [];
    $GLOBALS['duo_attachment_upload_root'] = '';
    $GLOBALS['duo_attachment_size_calls'] = 0;
    $GLOBALS['duo_attachment_mutate_size_call'] = 0;
    $GLOBALS['duo_attachment_size_roster'] = [
        'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
    ];
    $GLOBALS['duo_attachment_bad_filesize'] = false;
    $GLOBALS['duo_attachment_editor_warning'] = false;
    $GLOBALS['duo_attachment_editor_output'] = false;
    $GLOBALS['duo_attachment_big_guard_seen'] = false;

    function duo_attachment_filter_id(callable $callback): string {
        return $callback instanceof \Closure
            ? spl_object_hash($callback)
            : hash('sha256', serialize($callback));
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        global $wp_filter;
        $node = $wp_filter[$hook] ??= new WP_Hook();
        if (!$node instanceof WP_Hook) return false;
        $node->callbacks[$priority][duo_attachment_filter_id($callback)] = [
            'function' => $callback,
            'accepted_args' => $acceptedArgs,
        ];
        return true;
    }

    function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
        global $wp_filter;
        $node = $wp_filter[$hook] ?? null;
        $id = duo_attachment_filter_id($callback);
        if (!$node instanceof WP_Hook || !isset($node->callbacks[$priority][$id])) return false;
        unset($node->callbacks[$priority][$id]);
        if (($node->callbacks[$priority] ?? []) === []) unset($node->callbacks[$priority]);
        if ($node->callbacks === []) unset($wp_filter[$hook]);
        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
        global $wp_filter;
        $node = $wp_filter[$hook] ?? null;
        if (!$node instanceof WP_Hook) return $value;
        ksort($node->callbacks, SORT_NUMERIC);
        foreach ($node->callbacks as $callbacks) {
            foreach ($callbacks as $entry) {
                $args = array_slice([$value, ...$arguments], 0, $entry['accepted_args']);
                $value = ($entry['function'])(...$args);
            }
        }
        return $value;
    }

    function wp_upload_dir(mixed $time = null, bool $create = true): array {
        return ['basedir' => $GLOBALS['duo_attachment_upload_root'], 'error' => false];
    }

    function wp_get_registered_image_subsizes(): array {
        ++$GLOBALS['duo_attachment_size_calls'];
        $roster = $GLOBALS['duo_attachment_size_roster'];
        if ($GLOBALS['duo_attachment_mutate_size_call'] === $GLOBALS['duo_attachment_size_calls']) {
            $roster['thumbnail']['width'] += 1;
        }
        return $roster;
    }

    function get_post(int $id): object {
        return (object) ['ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => 'image/png'];
    }
    function is_wp_error(mixed $value): bool { return false; }
    function file_is_displayable_image(string $file): bool { return true; }

    function wp_get_image_editor(string $file): WP_Image_Editor_GD {
        if ($GLOBALS['duo_attachment_editor_warning']) {
            trigger_error('hostile-editor-warning-secret', E_USER_WARNING);
        }
        if ($GLOBALS['duo_attachment_editor_output']) echo 'hostile-editor-output-secret';
        return new WP_Image_Editor_GD();
    }

    function wp_generate_attachment_metadata(int $attachmentId, string $file): array {
        $threshold = apply_filters('big_image_size_threshold', 2560, [4000, 3000], $file, $attachmentId);
        if ($threshold !== false) {
            throw new \RuntimeException('test core did not observe Duo\'s identity-preserving big-image guard');
        }
        $GLOBALS['duo_attachment_big_guard_seen'] = true;
        $bytes = file_get_contents($file);
        if (!is_string($bytes)) throw new \RuntimeException('test core could not read its staging original');
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $derivative = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '-300x300.' . $extension;
        if (file_put_contents($derivative, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('test core could not write its staged derivative');
        }
        $size = strlen($bytes) + ($GLOBALS['duo_attachment_bad_filesize'] ? 1 : 0);
        $metadata = [
            'file' => $file,
            'filesize' => strlen($bytes),
            'height' => 1,
            'sizes' => [
                'thumbnail' => [
                    'file' => basename($derivative),
                    'filesize' => $size,
                    'height' => 1,
                    'mime-type' => 'image/png',
                    'width' => 1,
                ],
            ],
            'width' => 1,
        ];
        $guard = apply_filters(
            'update_post_metadata',
            null,
            $attachmentId,
            '_wp_attachment_metadata',
            $metadata,
            ''
        );
        if ($guard !== true) throw new \RuntimeException('test core metadata write was not intercepted');
        return apply_filters('wp_generate_attachment_metadata', $metadata, $attachmentId, 'create');
    }

    $root = dirname(__DIR__, 4);
    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/agent/src/Kernel/DurableFilesystem.php';
    require_once $root . '/agent/src/Kernel/PlainData.php';
    require_once $root . '/agent/src/Kernel/OptionState.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Repository/Ledger.php';
    require_once $root . '/agent/src/Grammar/Tokens.php';
    require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
    require_once $root . '/agent/src/Repository/CompiledArtifact.php';
    require_once $root . '/agent/src/Apply/AttachmentNativeMetadataGenerator.php';
    require_once $root . '/agent/src/Apply/AttachmentFilesystemTransaction.php';
    require_once $root . '/agent/src/Apply/AttachmentMaterializer.php';

    use Duo\ApplyFieldMaterializer;
    use Duo\AttachmentFilesystemTransaction;
    use Duo\AttachmentMaterializer;
    use Duo\AttachmentNativeMetadataGenerator;
    use Duo\CompiledRepository;
    use Duo\Db;
    use Duo\PlainData;
    use Duo\Policy;
    use Duo\Tokens;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) $failures[] = $message;
    };
    $throws = static function (callable $operation, string $needle, string $message) use ($check): void {
        try {
            $operation();
            $check(false, $message);
        } catch (\Throwable $failure) {
            $matched = false;
            for ($cursor = $failure; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
                if (str_contains($cursor->getMessage(), $needle)) {
                    $matched = true;
                    break;
                }
            }
            $check($matched, $message);
        }
    };
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $removeTree($entry->getPathname());
        }
        @rmdir($path);
    };

    $temporary = $root . '/sandbox/tmp/duo-attachment-regress-' . bin2hex(random_bytes(8));
    $repository = $temporary . '/repository';
    $uploads = $temporary . '/wordpress/wp-content/uploads';
    if (!mkdir($repository, 0700, true) || !mkdir($uploads . '/2026/08', 0700, true)) {
        throw new \RuntimeException('could not create attachment regression roots');
    }
    $GLOBALS['duo_attachment_upload_root'] = $uploads;

    try {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        if (!is_string($png)) throw new \RuntimeException('invalid PNG fixture');
        $uuid = '9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29';
        $blob = hash('sha256', $png) . '.png';
        $front = [
            'alt' => 'portable alt',
            'file' => '2026/08/photo.png',
            'media' => $blob,
            'mime' => 'image/png',
            'type' => 'attachment',
            'uuid' => $uuid,
        ];
        $tree = [$uuid => ['data' => $front, 'type' => 'post']];
        $compiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $tree,
        ]);
        $work = [['uuid' => $uuid]];

        $original = $uploads . '/2026/08/photo.png';
        $stale = $uploads . '/2026/08/photo-150x150.png';
        $neighbor = $uploads . '/2026/08/photo-other.png';
        file_put_contents($original, 'prior-original');
        file_put_contents($stale, 'prior-owned-stale');
        file_put_contents($neighbor, 'unrelated-prefix-neighbor');

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $check($filesystem->phase() === null, 'an empty private control root has no pending attachment transaction');
        $check(!is_dir($repository . '/.duo'), 'a read-only pending probe does not create private control state');
        $preflightGenerator = new AttachmentNativeMetadataGenerator(
            static fn(int $id): never => throw new \LogicException('markerless preflight requested target lock')
        );
        $filesystem->prepare($work, $tree, $preflightGenerator);
        $check(
            is_file($repository . '/.duo/attachment-filesystem/current/journal.json'),
            'journal, staged bytes and before-images live under the repository-private .duo control root'
        );
        $check(
            !is_dir($uploads . '/.duo-attachment-apply') && !is_dir($uploads . '/.duo'),
            'no durable journal or before-image is published beneath the web-served uploads root'
        );
        $filesystem->register_attachment(41, $front, [
            '2026/08/photo.png',
            '2026/08/photo-150x150.png',
        ]);
        $authored = $filesystem->seal_authored_transaction();
        $check(is_array($authored) && $filesystem->phase() === 'authored_prepared', 'authored marker seals exact UUID-to-post-ID authority');
        $filesystem->commit_authored_transaction($authored['value']);
        $check(file_get_contents($original) === $png, 'compiled original publishes only after the authored COMMIT marker is supplied');
        $filesystem->end();

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $filesystem->recover_pending_with_marker($authored['value']);
        $check($filesystem->phase() === 'originals_published', 'authored-marker crash recovery resumes at exact post-COMMIT original bytes');

        $generator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/png');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $GLOBALS['duo_attachment_mutate_size_call'] = 0;
        $GLOBALS['duo_attachment_big_guard_seen'] = false;
        $filesystem->generate_metadata($generator);
        $check(Db::$starts === 1 && Db::$rollbacks === 1, 'native generator always settles its rollback-only metadata transaction');
        $check($GLOBALS['duo_attachment_big_guard_seen'], 'large-image replacement is disabled while ordinary registered sizes still generate');
        $check(
            hash_equals($authored['value'], $filesystem->pending_marker_identity()['value']),
            'generation evidence does not mutate the stable authored marker authority'
        );
        $metadataRows = $filesystem->generated_metadata_rows();
        $metadata = PlainData::decode_serialized((string) $metadataRows[0]['metadata'], 'attachment test metadata');
        $check(
            ($metadata['file'] ?? null) === '2026/08/photo.png'
                && !str_contains(serialize($metadata), 'attachment-filesystem')
                && ($metadata['filesize'] ?? null) === strlen($png),
            'native metadata is canonicalized to the authored path with exact sealed filesize and no staging path'
        );
        $filesystem->publish_derivatives();
        $metadataMarker = $filesystem->seal_metadata_transaction();
        $check(!hash_equals($authored['value'], $metadataMarker['value']), 'metadata phase atomically uses a distinct generation-bound marker');
        $filesystem->metadata_transaction_committed($metadataMarker['value']);
        $filesystem->end();

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $filesystem->recover_pending_with_marker($metadataMarker['value']);
        $check($filesystem->phase() === 'metadata_committed', 'metadata-marker crash recovery resumes without repeating authored publication');
        $filesystem->remove_stale_derivatives($metadataMarker['value']);
        $check(!file_exists($stale), 'only an exact path owned by prior _wp_attachment_metadata is removed as stale');
        $check(file_get_contents($neighbor) === 'unrelated-prefix-neighbor', 'an unrelated same-prefix upload is never inferred to be deletion-owned');
        $check(is_file($uploads . '/2026/08/photo-300x300.png'), 'sealed native derivative bytes publish at the exact target path');
        $filesystem->cleanup_complete(null);
        $filesystem->end();
        $check(!is_dir($repository . '/.duo/attachment-filesystem/current'), 'terminal marker-free cleanup removes the reusable current slot');

        $secondUuid = '1a2b3c4d-5e6f-4789-8abc-def012345678';
        $secondFront = $front;
        $secondFront['uuid'] = $secondUuid;
        $secondFront['file'] = '2026/08/second.png';
        $secondTree = [$secondUuid => ['data' => $secondFront, 'type' => 'post']];
        $secondCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $secondTree,
        ]);
        $second = new AttachmentFilesystemTransaction($secondCompiled, $repository);
        $priorUmask = umask(0000);
        try {
            $second->prepare([['uuid' => $secondUuid]], $secondTree, $preflightGenerator);
        } finally {
            umask($priorUmask);
        }
        $check($second->phase() === 'prepared', 'a different attachment transaction can start after terminal cleanup');
        $journalMode = fileperms($repository . '/.duo/attachment-filesystem/current/journal.json');
        $check(
            is_int($journalMode) && ($journalMode & 0077) === 0,
            'private durable files remain owner-only under a deliberately permissive process umask'
        );
        $second->rollback_authored_transaction(null);
        $second->end();

        $badUuid = '2b3c4d5e-6f70-489a-8bcd-ef0123456789';
        $badBytes = "%PDF-1.4\n%%EOF\n";
        $badBlob = hash('sha256', $badBytes) . '.pdf';
        $badFront = [
            'alt' => '',
            'file' => '2026/08/unsupported.pdf',
            'media' => $badBlob,
            'mime' => 'application/pdf',
            'type' => 'attachment',
            'uuid' => $badUuid,
        ];
        $badTree = [$badUuid => ['data' => $badFront, 'type' => 'post']];
        $badCompiled = CompiledRepository::create([
            'media' => [$badBlob => ['base64' => base64_encode($badBytes), 'sha256' => hash('sha256', $badBytes)]],
            'tree' => $badTree,
        ]);
        $bad = new AttachmentFilesystemTransaction($badCompiled, $repository);
        $startsBeforePreflight = Db::$starts;
        $throws(
            static fn() => $bad->prepare([['uuid' => $badUuid]], $badTree, $preflightGenerator),
            'markerless preflight failed',
            'deterministic unsupported-media refusal occurs in the markerless pre-authored boundary'
        );
        $check(
            Db::$starts === $startsBeforePreflight
                && !file_exists($uploads . '/2026/08/unsupported.pdf'),
            'markerless media refusal starts no database transaction and changes no upload byte'
        );
        $bad->rollback_authored_transaction(null);
        $bad->end();
        $check(
            !is_dir($repository . '/.duo/attachment-filesystem/current'),
            'markerless media refusal cleanup removes its private journal without recovery state'
        );

        $renameUuid = '3c4d5e6f-7081-49ab-8cde-f0123456789a';
        $renameFront = [
            'alt' => '',
            'file' => '2026/09/renamed.png',
            'media' => $blob,
            'mime' => 'image/png',
            'type' => 'attachment',
            'uuid' => $renameUuid,
        ];
        $renameTree = [$renameUuid => ['data' => $renameFront, 'type' => 'post']];
        $renameCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $renameTree,
        ]);
        mkdir($uploads . '/2026/09', 0700, true);
        $oldOriginal = $uploads . '/2026/08/legacy.png';
        $oldDerivative = $uploads . '/2026/08/legacy-150x150.png';
        $oldNeighbor = $uploads . '/2026/08/legacy-other.png';
        file_put_contents($oldOriginal, 'legacy-original');
        file_put_contents($oldDerivative, 'legacy-owned-derivative');
        file_put_contents($oldNeighbor, 'legacy-unowned-neighbor');
        $rename = new AttachmentFilesystemTransaction($renameCompiled, $repository);
        $rename->prepare([['uuid' => $renameUuid]], $renameTree, $preflightGenerator);
        $rename->register_attachment(52, $renameFront, [
            '2026/08/legacy.png',
            '2026/08/legacy-150x150.png',
        ]);
        $renameAuthored = $rename->seal_authored_transaction();
        $rename->commit_authored_transaction($renameAuthored['value']);
        $check(
            is_file($uploads . '/2026/09/renamed.png') && is_file($oldOriginal),
            'directory/name move publishes the new original but retains old owned bytes until metadata commits'
        );
        $renameGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/png');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $rename->generate_metadata($renameGenerator);
        $rename->publish_derivatives();
        $renameMetadata = $rename->seal_metadata_transaction();
        $rename->metadata_transaction_committed($renameMetadata['value']);
        $rename->remove_stale_derivatives($renameMetadata['value']);
        $check(
            !file_exists($oldOriginal)
                && !file_exists($oldDerivative)
                && file_get_contents($oldNeighbor) === 'legacy-unowned-neighbor',
            'rename removes only exact old attached-file/metadata ownership after new metadata is durable'
        );
        $rename->cleanup_complete(null);
        $rename->end();

        $collisionUuid = '4d5e6f70-8192-4abc-8def-0123456789ab';
        $collisionFront = $renameFront;
        $collisionFront['uuid'] = $collisionUuid;
        $collisionFront['file'] = '2026/09/collision.png';
        $collisionTree = [$collisionUuid => ['data' => $collisionFront, 'type' => 'post']];
        $collisionCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $collisionTree,
        ]);
        $collisionPath = $uploads . '/2026/09/collision.png';
        file_put_contents($collisionPath, $png);
        $collision = new AttachmentFilesystemTransaction($collisionCompiled, $repository);
        $collision->prepare([['uuid' => $collisionUuid]], $collisionTree, $preflightGenerator);
        $throws(
            static fn() => $collision->register_attachment(53, $collisionFront, []),
            'present without exact prior attached-file ownership',
            'even desired-hash destination bytes cannot be overwritten/adopted without exact prior attachment ownership'
        );
        $check(file_get_contents($collisionPath) === $png, 'unowned destination collision refusal changes no target byte');
        $collision->rollback_authored_transaction(null);
        $collision->end();

        $standalone = $repository . '/standalone.png';
        file_put_contents($standalone, $png);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $generator->generate(41, $standalone);
        $probe = static fn(): bool => true;
        $priorHandler = set_error_handler($probe);
        $check($priorHandler === null, 'generator restores PHP\'s default error handler when set_error_handler originally returned null');
        restore_error_handler();

        $preexisting = static fn(): bool => true;
        set_error_handler($preexisting);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $generator->generate(41, $standalone);
        $observed = set_error_handler($probe);
        $check($observed === $preexisting, 'generator restores an exact preexisting error handler after native work');
        restore_error_handler();
        restore_error_handler();

        $GLOBALS['duo_attachment_editor_output'] = true;
        $GLOBALS['duo_attachment_size_calls'] = 0;
        ob_start();
        $generator->generate(41, $standalone);
        $escaped = ob_get_clean();
        $GLOBALS['duo_attachment_editor_output'] = false;
        $check($escaped === '', 'editor-preflight output is captured before it can corrupt canonical command output');

        $GLOBALS['duo_attachment_editor_warning'] = true;
        $GLOBALS['duo_attachment_size_calls'] = 0;
        ob_start();
        $warning = null;
        try { $generator->generate(41, $standalone); } catch (\Throwable $failure) { $warning = $failure; }
        $escaped = ob_get_clean();
        $GLOBALS['duo_attachment_editor_warning'] = false;
        $check(
            $warning instanceof \Throwable
                && $escaped === ''
                && !str_contains($warning->getMessage(), 'hostile-editor-warning-secret'),
            'editor-preflight warnings are contained and value-free before native generation'
        );

        $hostile = static fn(mixed $value): mixed => $value;
        add_filter('pre_wp_filesize', $hostile, 10, 1);
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'unreviewed callback topology',
            'WordPress 7.1 filesize callback topology refuses before parser/editor work'
        );
        remove_filter('pre_wp_filesize', $hostile, 10);
        add_filter('option_thumbnail_size_w', $hostile, 10, 1);
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'image-size option callback topology',
            'dynamic image-size option filters are closed rather than bypassing the static hook roster'
        );
        remove_filter('option_thumbnail_size_w', $hostile, 10);

        $GLOBALS['duo_attachment_size_calls'] = 0;
        $GLOBALS['duo_attachment_mutate_size_call'] = 2;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'roster changed at its transaction boundary',
            'registered-size TOCTOU drift refuses inside the same rollback-only transaction Core will use'
        );
        $GLOBALS['duo_attachment_mutate_size_call'] = 0;

        $GLOBALS['duo_attachment_size_roster'] = [
            'one' => ['width' => 16384, 'height' => 16384, 'crop' => true],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'aggregate pixel-work bound',
            'a syntactically valid registered-size roster cannot exceed the bounded output-pixel work authority'
        );
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => ['middle', 'everywhere']],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'registered image-size row is malformed',
            'crop coordinates use the exact bounded WordPress enum rather than arbitrary strings'
        );
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
        ];

        $polyglot = $repository . '/polyglot.png';
        file_put_contents($polyglot, $png . '<?php secret');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $polyglot),
            'carries trailing bytes',
            'a valid image prefix with undeclared trailing payload is refused before the editor'
        );
        $wrongExtension = $repository . '/mismatch.jpg';
        file_put_contents($wrongExtension, $png);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $wrongExtension),
            'MIME/extension-mismatched',
            'declared MIME, target extension and detected bytes must select one exact native parser path'
        );
        $pdf = $repository . '/document.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $pdfGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'application/pdf');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $pdfGenerator->generate(41, $pdf),
            'unsupported or MIME/extension-mismatched media class',
            'PDF/Imagick multi-page decompression is an explicit fail-closed boundary'
        );

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $bomb = "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', 100000, 100000, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress(''))
            . $chunk('IEND', '');
        $bombPath = $repository . '/pixel-bomb.png';
        file_put_contents($bombPath, $bomb);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $bombPath),
            'source dimensions exceed',
            'small compressed bytes cannot authorize an extreme source-pixel allocation'
        );

        $budget = new \ReflectionMethod(AttachmentFilesystemTransaction::class, 'add_transaction_bytes');
        $total = 0;
        $arguments = [&$total, 1073741823];
        $budget->invokeArgs($filesystem, $arguments);
        $arguments = [&$total, 1];
        $budget->invokeArgs($filesystem, $arguments);
        $check($total === 1073741824, 'transaction-wide budget admits its exact 1 GiB boundary without allocating it');
        $throws(
            static function () use ($budget, $filesystem, &$total): void {
                $arguments = [&$total, 1];
                $budget->invokeArgs($filesystem, $arguments);
            },
            '1 GiB aggregate',
            'transaction-wide budget refuses the first byte above its exact aggregate authority'
        );
        $many = 0;
        for ($i = 0; $i < 1024; ++$i) {
            $arguments = [&$many, 1048576];
            $budget->invokeArgs($filesystem, $arguments);
        }
        $check($many === 1073741824, 'many individually small files are charged to the same transaction-wide frontier');

        $symlinkTarget = $temporary . '/real-repository';
        $symlinkRepo = $temporary . '/repository-link';
        mkdir($symlinkTarget, 0700);
        symlink($symlinkTarget, $symlinkRepo);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $symlinkRepo))->load_pending(),
            'exact physical repository control root',
            'a symlinked private control root refuses before any journal mutation'
        );
        $webRepo = $uploads . '/repository';
        mkdir($webRepo, 0700);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $webRepo))->load_pending(),
            'outside the web-served uploads tree',
            'a repository/control root beneath uploads refuses rather than exposing durable bytes'
        );
        $unsafeRepo = $temporary . '/unsafe-repository';
        mkdir($unsafeRepo . '/.duo/attachment-filesystem', 0755, true);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $unsafeRepo))->load_pending(),
            'permits group/other access',
            'a hostile precreated non-private attachment control subtree refuses before use'
        );

        $policy = (new \ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
        $tokens = (new \ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
        $fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
        $attachmentMaterializer = new AttachmentMaterializer($fieldMaterializer, $compiled, $repository);
        $check($attachmentMaterializer instanceof AttachmentMaterializer, 'AttachmentMaterializer composes the durable boundary with an explicit private repository root');
        $constructor = (new \ReflectionClass(AttachmentMaterializer::class))->getConstructor();
        $check(
            array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters())
                === ['fieldMaterializer', 'compiled', 'repositoryRoot'],
            'constructor authority is exactly field materializer, immutable artifact and private repository root'
        );
    } finally {
        $removeTree($temporary);
    }

    if ($failures !== []) {
        echo "\n" . count($failures) . " failure(s):\n";
        foreach ($failures as $failure) echo "  - $failure\n";
        exit(1);
    }
    echo "\nall AttachmentMaterializer checks passed\n";
}
