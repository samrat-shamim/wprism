<?php
namespace Duo;

require_once __DIR__ . '/CommandRefusal.php';

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
     *   media_ref:array{0:string,1:array{path?:string,bytes?:string}}
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
        $up = wp_upload_dir(null, false);
        $localPath = trailingslashit($up['basedir']) . $attachedFile;
        $source = is_file($localPath) ? ['path' => $localPath] : null;

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
        $hasPath = array_key_exists('path', $source);
        $hasBytes = array_key_exists('bytes', $source);
        if ($hasPath === $hasBytes) {
            throw new \RuntimeException(
                "duo: attachment $attachmentId offload provider returned an invalid duo_attachment_capture_source value;"
                . " expected exactly one of 'path' or 'bytes'"
            );
        }
        if ($hasPath) {
            if (!is_string($source['path']) || $source['path'] === ''
                || !is_file($source['path']) || !is_readable($source['path'])) {
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider path is not a readable file"
                );
            }
            $sha = hash_file('sha256', $source['path']);
            if ($sha === false) {
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider path could not be hashed"
                );
            }
        } else {
            if (!is_string($source['bytes'])) {
                throw new \RuntimeException(
                    "duo: attachment $attachmentId offload provider bytes must be a string"
                );
            }
            $sha = hash('sha256', $source['bytes']);
        }
        $ext = pathinfo($attachedFile, PATHINFO_EXTENSION);
        $mediaFile = $sha . ($ext ? ".$ext" : '');
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
}
