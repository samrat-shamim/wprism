<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';

/**
 * Read-only attachment source observation and content-addressed projection.
 *
 * Capture retains post/meta identity, canonical entity assembly, and media
 * publication. This boundary only resolves the attachment's local or
 * provider-backed bytes, validates the provider contract, hashes the exact
 * source, and returns the four attachment front fields plus publication ref.
 */
final class MediaCapture {
    /**
     * @return array{
     *   front:array{file:string,media:string,mime:string,alt:string},
     *   media_ref:array{0:string,1:array{path?:string,bytes?:string,witness:array{extension:string,sha256:string,size:int}}}
     * }
     */
    public function capture(
        int $attachmentId,
        ?string $attachedFile,
        string $mime,
        string $alt,
        bool $strictReadOnly = false
    ): array {
        if (!$attachedFile) {
            throw new \RuntimeException("duo: attachment $attachmentId has no _wp_attached_file");
        }
        // Core routes MIME branches case-sensitively (notably its historic
        // camel-case macro-enabled Office spellings), so retain the raw
        // attachment value and let MediaPayloadAuthority prove that branch.
        MediaPayloadAuthority::assertRelativeUploadPath($attachedFile);
        $uploads = $this->uploadsRoot();
        $localPath = $uploads['path'] . '/' . $attachedFile;
        $source = $this->localSource($uploads['path'], $localPath);

        if ($strictReadOnly) {
            $this->assertStrictProviderAbsent();
        }

        /**
         * Lets an offload adapter supply attachment bytes without requiring
         * a persistent local uploads copy. The strict result contract is
         * exactly one readable path or one raw byte string.
         */
        if (!$strictReadOnly) {
            $source = apply_filters(
                'duo_attachment_capture_source',
                $source,
                $attachmentId,
                $attachedFile,
                $localPath
            );
        } elseif ($source === null) {
            throw CommandRefusalException::explainObservationPrecondition(
                new \RuntimeException(
                    'duo: strict attachment observation has no local media source; '
                    . 'the external offload hook is deliberately not invoked by explain'
                )
            );
        }
        if ($source === null) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId file '$attachedFile' is not present locally and no offload provider"
                . ' supplied bytes via duo_attachment_capture_source; capture cannot proceed for this attachment'
            );
        }
        if (!is_array($source)) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId offload provider returned an invalid duo_attachment_capture_source value;"
                . " expected exactly ['path' => <readable path>] or ['bytes' => <raw bytes>]"
            );
        }
        if (count($source) !== 1) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId offload provider returned an invalid duo_attachment_capture_source value;"
                . " expected exactly one of 'path' or 'bytes'"
            );
        }
        $hasPath = array_key_exists('path', $source);
        $hasBytes = array_key_exists('bytes', $source);
        if ($hasPath === $hasBytes) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId offload provider returned an invalid duo_attachment_capture_source value;"
                . " expected exactly one of 'path' or 'bytes'"
            );
        }
        if ($hasPath) {
            if (!is_string($source['path']) || $source['path'] === '') {
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider path is not a readable file"
                );
            }
            try {
                $source['path'] = MediaPayloadAuthority::physicalLocalFilePath($source['path']);
            } catch (\Throwable $error) {
                // The public provider-contract refusal predates the physical
                // topology proof. Keep it byte-stable while the previous
                // exception remains available to the caller's error chain.
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider path is not a readable file",
                    0,
                    $error
                );
            }
            $witness = MediaPayloadAuthority::observeFile($source['path'], $attachedFile, $mime);
        } else {
            if (!is_string($source['bytes'])) {
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider bytes must be a string"
                );
            }
            $witness = MediaPayloadAuthority::observeBytes($source['bytes'], $attachedFile, $mime);
        }
        if ($this->uploadsRoot() !== $uploads) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId uploads root changed during its bounded media observation"
            );
        }
        $mediaFile = MediaPayloadAuthority::mediaName($witness);
        $source['witness'] = $witness;
        return [
            'front' => [
                'file' => $attachedFile,
                'media' => $mediaFile,
                'mime' => $mime,
                'alt' => $alt,
            ],
            'media_ref' => [$mediaFile, $source],
        ];
    }

    /** @return array{configured:string,dev:string,ino:string,path:string} */
    private function uploadsRoot(): array {
        $uploads = wp_upload_dir(null, false);
        $path = is_array($uploads) ? ($uploads['basedir'] ?? null) : null;
        $error = is_array($uploads) ? ($uploads['error'] ?? null) : null;
        if (!is_string($path)
            || $path === ''
            || !str_starts_with($path, '/')
            || ($error !== false && $error !== '')
            || str_contains($path, "\0")) {
            throw new \RuntimeException('duo: attachment media could not resolve a healthy absolute uploads root');
        }
        $configured = rtrim($path, '/');
        clearstatcache(true, $configured);
        $stat = @stat($configured);
        $real = realpath($configured);
        if (!is_array($stat)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0040000
            || !is_string($real)
            || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException('duo: attachment media uploads root is missing, special, or lacks a physical identity');
        }
        return [
            'configured' => $configured,
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'path' => rtrim($real, '/'),
        ];
    }

    /** @return ?array{path:string} */
    private function localSource(string $root, string $path): ?array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) return null;
        $parent = dirname($path);
        $realParent = realpath($parent);
        if (!is_string($realParent)
            || !hash_equals($parent, rtrim($realParent, '/'))
            || (!hash_equals($realParent, $root) && !str_starts_with($realParent, $root . '/'))
            || is_link($path)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || !is_readable($path)) {
            return null;
        }
        return ['path' => MediaPayloadAuthority::physicalLocalFilePath($path)];
    }

    private function assertStrictProviderAbsent(): void {
        global $wp_filter;
        if (!is_array($wp_filter ?? null) || !isset($wp_filter['duo_attachment_capture_source'])) return;
        $node = $wp_filter['duo_attachment_capture_source'];
        $callbacks = is_object($node) && property_exists($node, 'callbacks')
            ? $node->callbacks
            : null;
        if (!is_array($callbacks) || $callbacks !== []) {
            throw CommandRefusalException::explainObservationPrecondition(
                new \RuntimeException(
                    'duo: strict attachment observation refuses the registered offload source callback topology'
                )
            );
        }
    }
}
