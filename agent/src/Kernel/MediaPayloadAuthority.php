<?php
declare(strict_types=1);

namespace WPrism;

/**
 * One target-independent byte authority for portable attachment originals.
 *
 * The repository format embeds referenced media as base64 in one canonical
 * artifact. A path may therefore cross filesystem hashing cheaply, but it may
 * not cross a full read or base64 allocation until its regular-file shape,
 * byte count, content address and current PHP memory headroom are known. The
 * 256 MiB per-file and 1 GiB aggregate ceilings are the existing durable
 * attachment transaction frontiers; the dynamic headroom proof narrows them
 * before an in-memory operation instead of pretending those filesystem limits
 * are also safe PHP allocation limits.
 *
 * The exact Core 6.9.2/7.0.3/7.1 branch predicate is the classification
 * authority, not wp_get_mime_types(): four exact raster MIME/extension pairs
 * take the closed GD path; `image/*`, `audio/*`, `video/*`, and
 * `application/pdf` are refused because Core may delegate or create cover
 * rows; every syntactically valid remaining MIME reaches only `filesize`
 * before the separately closed metadata filter. This admits real custom
 * opaque application/text/font MIME spellings (including Core's camel-case
 * macro-enabled Office values) without pretending a whitelist is the Core
 * branch. Blob identity remains only `{sha256,size,extension}`: identical
 * bytes may legally back attachments whose independently-routed MIME rows
 * take different safe generic/raster metadata branches.
 */
final class MediaPayloadAuthority {
    public const MAX_FILE_BYTES = 8589934592;
    public const MAX_AGGREGATE_BYTES = 68719476736;
    public const MAX_INLINE_ARTIFACT_BYTES = 8388608;
    public const MAX_CATALOG_FILES = 16777216;
    // SHA-256 name (64) + dot + extension must fit NAME_MAX=255. This keeps
    // every current-main materializable extension (>16 included) portable.
    private const MAX_MEDIA_EXTENSION_BYTES = 190;

    /**
     * An artifact has a structured state partition in addition to encoded
     * media. This is an absolute wire-format ceiling, not a promise that a
     * PHP process can allocate it: readArtifactDocument() proves current
     * headroom before it reads, decodes, and re-encodes any candidate.
     */
    public const MAX_ARTIFACT_DOCUMENT_BYTES = 1610612736;

    private const MEMORY_RESERVE_BYTES = 16777216;
    private const UNLIMITED_MEMORY_FALLBACK_BYTES = 536870912;
    private const READ_CHUNK_BYTES = 1048576;
    private const MAX_ARTIFACT_STRUCTURE_DEPTH = 64;
    private const MAX_ARTIFACT_STRUCTURE_SLOTS = 16777216;
    private const ARTIFACT_SLOT_MEMORY_BYTES = 1024;
    private const MAX_IMAGE_DIMENSION = 16384;
    private const MAX_SOURCE_PIXELS = 67108864;
    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var array<string,list<string>> */
    private const RASTER_EXTENSIONS = [
        'image/gif' => ['gif'],
        'image/jpeg' => ['jpe', 'jpeg', 'jpg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * @return array{extension:string,sha256:string,size:int}
     */
    public static function observeFile(string $path, string $uploadPath, string $mime): array {
        self::assertRelativeUploadPath($uploadPath);
        $path = self::physicalLocalFilePath($path);
        $firstShape = self::assertFileShape($path);
        $firstHash = self::streamHash($path, $firstShape);

        // Deterministic test-only interleave for the prior defect: an
        // in-place same-size rewrite between reads must not publish a hybrid
        // content address as though it described a stable source state.
        if (defined('WPRISM_TEST_MODE') && WPRISM_TEST_MODE === true
            && is_callable($GLOBALS['wprism_media_observation_interleave'] ?? null)) {
            ($GLOBALS['wprism_media_observation_interleave'])($path);
        }

        $secondShape = self::assertFileShape($path);
        if ($secondShape !== $firstShape) {
            throw new \RuntimeException('wprism: media source identity changed between its stability observations');
        }
        $secondHash = self::streamHash($path, $secondShape);
        if (!hash_equals($firstHash, $secondHash)) {
            throw new \RuntimeException('wprism: media source bytes changed between its stability observations');
        }
        $extension = self::extensionFor($uploadPath);
        if (self::kindFor($mime, $extension) !== 'raster') {
            return ['extension' => $extension, 'sha256' => $secondHash, 'size' => $secondShape['size']];
        }
        self::assertMemoryHeadroom($secondShape['size'], 2, 'media container validation');
        $bytes = self::readOpenedFile($path, $secondShape, $secondHash);
        $witness = self::witness($bytes, $uploadPath, $mime);
        if (!hash_equals($secondHash, $witness['sha256'])) {
            throw new \RuntimeException('wprism: media source changed after its stable content observation');
        }
        return $witness;
    }

    /**
     * @return array{extension:string,sha256:string,size:int}
     */
    public static function observeBytes(string $bytes, string $uploadPath, string $mime): array {
        self::assertRelativeUploadPath($uploadPath);
        $size = strlen($bytes);
        self::assertFileBytes($size);
        self::assertMemoryHeadroom($size, 2, 'provider media validation');
        return self::witness($bytes, $uploadPath, $mime);
    }

    /**
     * Rebind a captured source immediately before a publication or refresh
     * payload consumes it. Capturing a stable witness is not publication
     * authority: a path can be changed while the database snapshot remains
     * open, and provider bytes can be replaced in an in-memory candidate.
     *
     * @param array{path?:string,bytes?:string,witness:array{extension:string,sha256:string,size:int}} $source
     */
    public static function sourceBytes(string $name, array $source): string {
        $hasPath = array_key_exists('path', $source);
        $hasBytes = array_key_exists('bytes', $source);
        $witness = $source['witness'] ?? null;
        if (count($source) !== 2
            || $hasPath === $hasBytes
            || !is_array($witness)) {
            throw new \RuntimeException('wprism: captured media source has an invalid bounded payload contract');
        }
        self::assertWitness($witness);
        self::assertMediaName($name, $witness);
        if ($hasPath) {
            if (!is_string($source['path'])) {
                throw new \RuntimeException('wprism: captured media source path is malformed');
            }
            return self::readFile($source['path'], $witness);
        }
        if (!is_string($source['bytes'])) {
            throw new \RuntimeException('wprism: captured media source bytes are malformed');
        }
        self::assertBytes($source['bytes'], $witness);
        return $source['bytes'];
    }

    /**
     * A persisted compiled artifact must be physically local, stable while
     * observed, and affordable before Canon::decode()/from_array() can add
     * two further representations. This closes the OOM boundary before the
     * first whole-document read rather than after an untrusted base64 string
     * has already entered PHP memory.
     */
    /**
     * @param null|array{dev:string,ino:string,mode:int,size:int} $expectedShape
     */
    public static function readArtifactDocument(string $path, ?array $expectedShape = null): string {
        $path = self::physicalLocalFilePath($path);
        $firstShape = self::assertFileShape($path, self::MAX_ARTIFACT_DOCUMENT_BYTES);
        if ($expectedShape !== null && $firstShape !== self::assertArtifactDocumentShape($expectedShape)) {
            throw new \RuntimeException('wprism: compiled artifact pathname does not match its bounded transport handoff');
        }
        // A wire-size multiplier cannot bound json_decode(..., true): a
        // 10,000,001-byte `[0,...]` document reached a 128 MiB allocation
        // failure on PHP 8.5.6 because packed zvals/hash tables expand by
        // cardinality, not textual byte length. Count delimiters streaming
        // before hashing or retaining the document, then reserve a
        // deliberately conservative 1 KiB per accepted PHP array slot.
        $structure = self::artifactStructure($path, $firstShape);
        self::assertDocumentHeadroom(
            $firstShape['size'],
            $structure['slots'],
            'compiled artifact read/decode/canonicalization'
        );
        $firstHash = self::streamHash($path, $firstShape);
        // This narrow test interleave proves that the persisted-artifact
        // boundary has the same stability rule as capture: an equal-length
        // rewrite must fail before Canon can decode a mixed document.
        if (defined('WPRISM_TEST_MODE') && WPRISM_TEST_MODE === true
            && is_callable($GLOBALS['wprism_compiled_artifact_observation_interleave'] ?? null)) {
            ($GLOBALS['wprism_compiled_artifact_observation_interleave'])($path);
        }
        $secondShape = self::assertFileShape($path, self::MAX_ARTIFACT_DOCUMENT_BYTES);
        $secondHash = self::streamHash($path, $secondShape);
        if ($secondShape !== $firstShape || !hash_equals($firstHash, $secondHash)) {
            throw new \RuntimeException('wprism: compiled artifact changed between bounded file observations');
        }
        $secondStructure = self::artifactStructure($path, $secondShape);
        if ($secondStructure !== $structure) {
            throw new \RuntimeException('wprism: compiled artifact structure changed between bounded file observations');
        }
        self::assertDocumentHeadroom(
            $secondShape['size'],
            $secondStructure['slots'],
            'compiled artifact read/decode/canonicalization'
        );
        return self::readOpenedFile($path, $secondShape, $secondHash);
    }

    /**
     * Validate every encoded artifact media row before canonical artifact
     * hashing. The raw aggregate ceiling is absolute; dynamic headroom still
     * narrows it for the base64/array/canonical-JSON representations that are
     * simultaneously live at this boundary.
     *
     * @param array<string,mixed> $media
     * @param array<string,mixed> $tree
     */
    public static function assertArtifactMedia(array $media, array $tree, ?string $mediaDirectory = null): void {
        $attachments = [];
        foreach ($tree as $entry) {
            $front = is_array($entry) && is_array($entry['data'] ?? null) ? $entry['data'] : null;
            if (!is_array($front)
                || !is_array($entry)
                || ($entry['type'] ?? null) !== 'post'
                || ($front['type'] ?? null) !== 'attachment') {
                continue;
            }
            $name = $front['media'] ?? null;
            if (!is_string($name)
                || !is_string($front['file'] ?? null)
                || !is_string($front['mime'] ?? null)) {
                throw new \RuntimeException('wprism: compiled artifact attachment media authority is malformed');
            }
            $attachments[$name][] = [
                'file' => $front['file'],
                'mime' => $front['mime'],
            ];
        }

        $aggregate = 0;
        $inline = 0;
        foreach ($media as $name => $row) {
            if (!is_string($name) || !is_array($row) || !is_string($row['sha256'] ?? null)) {
                throw new \RuntimeException('wprism: compiled artifact media row is malformed');
            }
            $parsed = self::parseMediaName($name);
            if (!hash_equals($parsed['sha256'], $row['sha256'])) {
                throw new \RuntimeException('wprism: compiled artifact media row disagrees with its content-addressed name');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys === ['base64', 'sha256'] && is_string($row['base64'])) {
                $size = self::canonicalBase64DecodedLength($row['base64'], 'compiled artifact media payload');
                $inline = self::addToAggregate($inline, $size);
            } elseif ($keys === ['sha256', 'size', 'source']
                && $row['source'] === 'repository'
                && is_int($row['size'])) {
                self::assertFileBytes($row['size']);
                if ($row['size'] <= self::MAX_INLINE_ARTIFACT_BYTES) {
                    throw new \RuntimeException('wprism: small compiled media must use its canonical inline encoding');
                }
                $size = $row['size'];
            } else {
                throw new \RuntimeException('wprism: compiled artifact media row is malformed');
            }
            $aggregate = self::addToAggregate($aggregate, $size);
            if (!isset($attachments[$name])) {
                throw new \RuntimeException('wprism: compiled artifact carries media without an attachment authority');
            }
        }
        foreach ($attachments as $name => $_refs) {
            if (!array_key_exists($name, $media)) {
                throw new \RuntimeException('wprism: compiled artifact attachment has no media payload');
            }
        }
        self::assertArtifactHeadroom($inline);

        foreach ($media as $name => $row) {
            $witness = null;
            $external = ($row['source'] ?? null) === 'repository';
            $bytes = $external ? null : self::decodeArtifactMedia($name, $row);
            foreach ($attachments[$name] as $front) {
                if ($external) {
                    if ($mediaDirectory === null) {
                        $current = [
                            'extension' => self::extensionFor($front['file']),
                            'sha256' => $row['sha256'],
                            'size' => $row['size'],
                        ];
                        self::kindFor($front['mime'], $current['extension']);
                    } else {
                        $current = self::observeFile(
                            rtrim($mediaDirectory, '/') . '/' . $name,
                            $front['file'],
                            $front['mime']
                        );
                    }
                } else {
                    $current = self::observeBytes((string) $bytes, $front['file'], $front['mime']);
                }
                self::assertMediaName($name, $current);
                if ($external && $current['size'] !== $row['size']) {
                    throw new \RuntimeException('wprism: compiled external media size does not verify');
                }
                if ($witness !== null && $witness !== $current) {
                    throw new \RuntimeException(
                        'wprism: compiled artifact media has inconsistent immutable blob witness authority'
                    );
                }
                $witness = $current;
            }
            unset($bytes);
        }
    }

    /**
     * Decode one artifact media row only after exact encoded-length arithmetic
     * has bounded the allocation. The caller may retain the original base64
     * inside the parsed artifact, so the two-copy proof is intentional.
     *
     * @param array{sha256:string,base64:string} $row
     */
    public static function decodeArtifactMedia(string $name, array $row): string {
        $parsed = self::parseMediaName($name);
        if (count($row) !== 2
            || !array_key_exists('sha256', $row)
            || !array_key_exists('base64', $row)
            || !hash_equals($parsed['sha256'], $row['sha256'])) {
            throw new \RuntimeException("wprism: compiled artifact media payload '$name' is malformed");
        }
        $length = self::canonicalBase64DecodedLength($row['base64'], 'compiled artifact media decode');
        self::assertMemoryHeadroom($length, 2, 'compiled artifact media decode');
        $bytes = base64_decode($row['base64'], true);
        if (!is_string($bytes)
            || strlen($bytes) !== $length
            || !hash_equals($row['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException("wprism: compiled artifact media payload '$name' does not verify");
        }
        return $bytes;
    }

    /**
     * Validate canonical base64 without allocating the decoded body. This is
     * shared by the host refresh path, which receives an already-parsed JSON
     * envelope and must still prove the decoded length before base64_decode.
     */
    public static function canonicalBase64DecodedLength(string $encoded, string $label): int {
        $length = strlen($encoded);
        if ($length === 0) {
            return 0;
        }
        if (($length % 4) !== 0) {
            throw new \RuntimeException("wprism: $label is not canonical base64");
        }
        $padding = str_ends_with($encoded, '==') ? 2 : (str_ends_with($encoded, '=') ? 1 : 0);
        $alphabetLength = $length - $padding;
        // A 127,552-byte valid marketplace payload made the prior repeated
        // PCRE group return PREG_JIT_STACKLIMIT_ERROR. strspn() checks the
        // same closed alphabet without making correctness depend on PCRE's
        // JIT or recursion limits; the separate suffix and unused-bit proofs
        // below retain canonical padding rather than merely valid decoding.
        if (strspn($encoded, self::BASE64_ALPHABET, 0, $alphabetLength) !== $alphabetLength) {
            throw new \RuntimeException("wprism: $label is not canonical base64");
        }
        if ($padding === 2 && (self::base64Value($encoded[$length - 3]) & 0x0F) !== 0) {
            throw new \RuntimeException("wprism: $label is not canonical base64");
        }
        if ($padding === 1 && (self::base64Value($encoded[$length - 2]) & 0x03) !== 0) {
            throw new \RuntimeException("wprism: $label is not canonical base64");
        }
        $decoded = (intdiv($length, 4) * 3) - $padding;
        self::assertFileBytes($decoded);
        return $decoded;
    }

    /**
     * Re-read one path under its exact prior content witness. Replacement by
     * an identical regular file is harmless; a byte/type/size change refuses.
     *
     * @param array{extension:string,sha256:string,size:int} $expected
     */
    public static function readFile(string $path, array $expected): string {
        self::assertWitness($expected);
        $path = self::physicalLocalFilePath($path);
        $shape = self::assertFileShape($path);
        if ($shape['size'] !== $expected['size']) {
            throw new \RuntimeException('wprism: media source size changed after its bounded observation');
        }
        self::assertMemoryHeadroom($shape['size'], 2, 'media payload read');
        $bytes = self::readOpenedFile($path, $shape, $expected['sha256']);
        self::assertBytes($bytes, $expected);
        return $bytes;
    }

    /**
     * Copy one witnessed source in bounded chunks. The caller owns an
     * unpublished staging handle and must discard it if this method refuses.
     *
     * @param array{extension:string,sha256:string,size:int} $expected
     * @param resource $output
     */
    public static function copyFileToStream(string $path, array $expected, $output): void {
        self::assertWitness($expected);
        if (!is_resource($output)) {
            throw new \InvalidArgumentException('wprism: media destination must be a stream');
        }
        $path = self::physicalLocalFilePath($path);
        $shape = self::assertFileShape($path);
        if ($shape['size'] !== $expected['size']) {
            throw new \RuntimeException('wprism: media source size changed after its bounded observation');
        }
        $input = @fopen($path, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('wprism: media source could not be opened for bounded transfer');
        }
        try {
            self::assertOpenedShape($input, $shape);
            $hash = hash_init('sha256');
            $written = 0;
            while (!feof($input)) {
                $chunk = fread($input, self::READ_CHUNK_BYTES);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('wprism: media source read failed during bounded transfer');
                }
                if ($chunk === '') {
                    if (!feof($input)) {
                        throw new \RuntimeException('wprism: media source stalled during bounded transfer');
                    }
                    break;
                }
                hash_update($hash, $chunk);
                self::writeStream($output, $chunk);
                $written += strlen($chunk);
                if ($written > $expected['size']) {
                    throw new \RuntimeException('wprism: media source grew during bounded transfer');
                }
            }
            if ($written !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
                throw new \RuntimeException('wprism: media payload bytes disagree with their content witness');
            }
            self::assertOpenedShape($input, $shape);
            self::assertNamedShape($path, $shape);
        } finally {
            fclose($input);
        }
    }

    /** @param resource $output */
    private static function writeStream($output, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $count = fwrite($output, substr($bytes, $offset));
            if (!is_int($count) || $count <= 0) {
                throw new \RuntimeException('wprism: media destination write failed');
            }
            $offset += $count;
        }
    }

    /**
     * @param array{extension:string,sha256:string,size:int} $expected
     */
    public static function assertBytes(string $bytes, array $expected): void {
        self::assertWitness($expected);
        self::assertMemoryHeadroom(strlen($bytes), 2, 'media payload revalidation');
        if (strlen($bytes) !== $expected['size']
            || !hash_equals($expected['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('wprism: media payload bytes disagree with their content witness');
        }
    }

    /**
     * @param array{extension:string,sha256:string,size:int} $witness
     */
    public static function mediaName(array $witness): string {
        self::assertWitness($witness);
        return $witness['sha256'] . '.' . $witness['extension'];
    }

    /**
     * @param array{extension:string,sha256:string,size:int} $witness
     */
    public static function assertMediaName(string $name, array $witness): void {
        if (!hash_equals(self::mediaName($witness), $name)) {
            throw new \RuntimeException('wprism: media filename disagrees with its exact immutable content witness');
        }
    }

    /**
     * Observe a content-addressed catalog blob without allocating its body.
     * Referenced blobs receive MIME/container validation separately.
     *
     * @return array{sha256:string,size:int}
     */
    public static function observeCatalogFile(string $path, string $name): array {
        $parsed = self::parseMediaName($name);
        $path = self::physicalLocalFilePath($path);
        $firstShape = self::assertFileShape($path);
        $first = self::streamHash($path, $firstShape);
        $secondShape = self::assertFileShape($path);
        $actual = self::streamHash($path, $secondShape);
        if ($secondShape !== $firstShape || !hash_equals($first, $actual)) {
            throw new \RuntimeException('wprism: media catalog blob changed between its stability observations');
        }
        if (!hash_equals($parsed['sha256'], $actual)) {
            throw new \RuntimeException('wprism: media catalog blob does not match its content address');
        }
        return ['sha256' => $actual, 'size' => $secondShape['size']];
    }

    /**
     * Read an otherwise opaque content-addressed catalog blob under the same
     * physical-file, stable-hash, exact-length, and memory rules as a media
     * source. Scoped overlays need this for historical/orphan bytes before an
     * attachment front-matter MIME is available to classify them.
     */
    public static function readCatalogBlob(string $path, string $name): string {
        $observed = self::observeCatalogFile($path, $name);
        $path = self::physicalLocalFilePath($path);
        $shape = self::assertFileShape($path);
        if ($shape['size'] !== $observed['size']) {
            throw new \RuntimeException('wprism: media catalog blob size changed after bounded observation');
        }
        self::assertMemoryHeadroom($shape['size'], 2, 'media catalog payload read');
        return self::readOpenedFile($path, $shape, $observed['sha256']);
    }

    /** @return array{extension:string,sha256:string} */
    public static function parseMediaName(string $name): array {
        if (preg_match('/^([0-9a-f]{64})\.([A-Za-z0-9]{1,190})$/D', $name, $match) !== 1) {
            throw new \RuntimeException('wprism: media filename is not a canonical bounded content address');
        }
        return ['sha256' => $match[1], 'extension' => $match[2]];
    }

    /** Add one unique payload to the shared absolute aggregate frontier. */
    public static function addToAggregate(int $current, int $bytes): int {
        if ($current < 0 || $bytes < 0 || $bytes > self::MAX_AGGREGATE_BYTES - $current) {
            throw new \RuntimeException('wprism: media payloads exceed their 64 GiB aggregate byte authority');
        }
        return $current + $bytes;
    }

    /**
     * Cataloguing streams every blob and retains only its name/hash map. The
     * aggregate byte ceiling remains the media authority; this independent
     * dynamic proof prices PHP hash-table/zval overhead without redefining a
     * normal media library at a small fixed file-count threshold.
     */
    public static function assertCatalogMapHeadroom(int $entries): void {
        if ($entries < 0 || $entries > self::MAX_CATALOG_FILES
            || $entries > intdiv(PHP_INT_MAX, self::ARTIFACT_SLOT_MEMORY_BYTES)) {
            throw new \RuntimeException('wprism: media catalog has an invalid bounded entry authority');
        }
        self::assertMemoryBudget(
            $entries * self::ARTIFACT_SLOT_MEMORY_BYTES,
            'media catalog name/hash map'
        );
    }

    /**
     * Canonical artifact construction holds raw/provider bytes, base64, a
     * normalized value and encoded JSON at overlapping points. Five copies
     * plus a 16 MiB engine reserve is conservative against the measured local
     * 128 MiB gate (24 MiB raw reached 102.8 MiB even after releasing raw).
     */
    public static function assertArtifactHeadroom(int $rawBytes): void {
        if ($rawBytes < 0 || $rawBytes > self::MAX_AGGREGATE_BYTES) {
            throw new \RuntimeException('wprism: media artifact input exceeds its aggregate byte authority');
        }
        self::assertMemoryHeadroom($rawBytes, 5, 'compiled media base64/canonicalization');
    }

    /** Refresh emits the same raw/base64/canonical-JSON shape as compile. */
    public static function assertRefreshExportHeadroom(int $rawBytes): void {
        if ($rawBytes < 0 || $rawBytes > self::MAX_AGGREGATE_BYTES) {
            throw new \RuntimeException('wprism: media refresh export exceeds its aggregate byte authority');
        }
        self::assertMemoryHeadroom($rawBytes, 5, 'refresh media base64/canonicalization');
    }

    /** Bound a remote refresh JSON envelope before json_decode retains it. */
    public static function assertRefreshEnvelopeBytes(int $bytes): void {
        if ($bytes < 0 || $bytes > self::MAX_ARTIFACT_DOCUMENT_BYTES) {
            throw new \RuntimeException('wprism: refresh export envelope exceeds its absolute byte authority');
        }
        self::assertMemoryHeadroom($bytes, 3, 'refresh export envelope parse/canonicalization');
    }

    /**
     * The transport has already retained this remote envelope, so prove its
     * delimiter density before json_decode can allocate PHP arrays. This is
     * the in-memory counterpart to the streamed persisted-artifact scan.
     */
    public static function assertRefreshEnvelope(string $document): void {
        self::assertRefreshEnvelopeBytes(strlen($document));
        $structure = self::documentStringStructure($document, 'refresh export envelope');
        self::assertDocumentHeadroom(
            strlen($document),
            $structure['slots'],
            'refresh export envelope parse/canonicalization'
        );
    }

    public static function kindFor(string $mime, string $extension): string {
        $routingExtension = strtolower($extension);
        $extensions = self::RASTER_EXTENSIONS[$mime] ?? null;
        if (is_array($extensions) && in_array($routingExtension, $extensions, true)) {
            return 'raster';
        }
        if (is_array($extensions)) {
            throw new \RuntimeException(
                'wprism: media payload is MIME/extension-mismatched for the reviewed raster branch'
            );
        }
        if ($mime === ''
            || strlen($mime) > 191
            || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $mime) !== 1) {
            throw new \RuntimeException('wprism: media payload has a malformed MIME authority');
        }
        // Non-raster originals deliberately take WPrism's closed filesize-only
        // metadata branch. Core's PDF cover-image and audio/video metadata
        // delegates are never called, so SVG/PDF/AV bytes remain portable
        // without granting an unbounded codec or subprocess authority.
        return 'generic';
    }

    /** @return array{extension:string,kind:string,mime:string,size:int} */
    public static function classifyFile(string $path, string $uploadPath, string $mime): array {
        $blob = self::observeFile($path, $uploadPath, $mime);
        return [
            'extension' => $blob['extension'],
            'kind' => self::kindFor($mime, $blob['extension']),
            'mime' => $mime,
            'size' => $blob['size'],
        ];
    }

    /** A canonical WordPress upload path, before any filesystem resolution. */
    public static function assertRelativeUploadPath(string $path): void {
        $segments = explode('/', $path);
        if ($path === ''
            || strlen($path) > 1024
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('//u', $path) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || array_filter($segments, static fn(string $part): bool =>
                $part === '' || $part === '.' || $part === '..' || strlen($part) > 255)) {
            throw new \RuntimeException('wprism: attachment media path is not a normalized relative upload path');
        }
    }

    /**
     * Provider/repository paths are local materializations, never wrappers or
     * final symlinks. Ancestor aliases (notably macOS /var -> /private/var)
     * are rebound to the physical final name which every later read retains.
     */
    public static function physicalLocalFilePath(string $path): string {
        if ($path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '://')) {
            throw new \RuntimeException('wprism: media source path is not an absolute local filesystem path');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat)
            || is_link($path)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || !is_string($real)) {
            throw new \RuntimeException('wprism: media source path is missing, linked, or not a local regular file');
        }
        // Store and later re-open the physical name, not the provider's
        // potentially symlinked ancestor spelling. A later ancestor retarget
        // therefore cannot redirect the already observed source authority.
        return $real;
    }

    /**
     * @return array{extension:string,sha256:string,size:int}
     */
    private static function witness(string $bytes, string $uploadPath, string $mime): array {
        $size = strlen($bytes);
        self::assertFileBytes($size);
        $extension = self::extensionFor($uploadPath);
        $kind = self::kindFor($mime, $extension);
        if ($kind === 'raster') {
            if (!self::imageContainerIsExact($mime, $bytes)) {
                throw new \RuntimeException(
                    'wprism: media raster container is truncated, malformed, or carries trailing bytes'
                );
            }
            self::assertRasterDecodable($mime, $bytes);
        }
        return [
            'extension' => $extension,
            'sha256' => hash('sha256', $bytes),
            'size' => $size,
        ];
    }

    private static function extensionFor(string $uploadPath): string {
        $extension = pathinfo($uploadPath, PATHINFO_EXTENSION);
        if ($extension === '' || strlen($extension) > self::MAX_MEDIA_EXTENSION_BYTES
            || preg_match('/^[A-Za-z0-9]+$/D', $extension) !== 1) {
            throw new \RuntimeException('wprism: media payload has no canonical portable extension authority');
        }

        return $extension;
    }

    /**
     * @param array{extension:string,sha256:string,size:int} $witness
     */
    private static function assertWitness(array $witness): void {
        if (array_keys($witness) !== ['extension', 'sha256', 'size']
            || !is_string($witness['extension'] ?? null)
            || !is_string($witness['sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $witness['sha256']) !== 1
            || !is_int($witness['size'] ?? null)) {
            throw new \RuntimeException('wprism: media content witness is malformed');
        }
        self::assertFileBytes($witness['size']);
    }

    /** @return array{dev:string,ino:string,mode:int,size:int} */
    private static function assertFileShape(string $path, int $maxBytes = self::MAX_FILE_BYTES): array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $mode = is_array($stat) ? ($stat['mode'] ?? null) : null;
        $size = is_array($stat) ? self::canonicalSize($stat['size'] ?? null) : null;
        if (!is_array($stat)
            || !is_int($mode)
            || ($mode & 0170000) !== 0100000
            || is_link($path)
            || !is_readable($path)
            || $size === null) {
            throw new \RuntimeException('wprism: media source is missing, unreadable, linked, or special');
        }
        if ($maxBytes === self::MAX_FILE_BYTES) {
            self::assertFileBytes($size);
        } elseif ($maxBytes < 0 || $size > $maxBytes) {
            throw new \RuntimeException('wprism: media source exceeds its bounded byte authority');
        }
        return [
            'dev' => (string) ($stat['dev'] ?? ''),
            'ino' => (string) ($stat['ino'] ?? ''),
            'mode' => $mode,
            'size' => $size,
        ];
    }

    /** @param array{dev:string,ino:string,mode:int,size:int} $shape */
    private static function assertArtifactDocumentShape(array $shape): array {
        if (array_keys($shape) !== ['dev', 'ino', 'mode', 'size']
            || !is_string($shape['dev'] ?? null)
            || !is_string($shape['ino'] ?? null)
            || !is_int($shape['mode'] ?? null)
            || !is_int($shape['size'] ?? null)
            || ($shape['mode'] & 0170000) !== 0100000
            || ($shape['mode'] & 0777) !== 0600
            || $shape['size'] < 0
            || $shape['size'] > self::MAX_ARTIFACT_DOCUMENT_BYTES) {
            throw new \RuntimeException('wprism: compiled artifact transport handoff identity is malformed');
        }
        return $shape;
    }

    /**
     * @param array{dev:string,ino:string,mode:int,size:int} $shape
     */
    private static function streamHash(string $path, array $shape): string {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: media source could not be opened for bounded hashing');
        }
        try {
            self::assertOpenedShape($handle, $shape);
            $hash = hash_init('sha256');
            $read = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('wprism: media source read failed during bounded hashing');
                }
                if ($chunk === '') {
                    if (!feof($handle)) {
                        throw new \RuntimeException('wprism: media source stalled during bounded hashing');
                    }
                    break;
                }
                $length = strlen($chunk);
                if ($read > $shape['size'] - $length) {
                    throw new \RuntimeException('wprism: media source grew during bounded hashing');
                }
                $read += $length;
                hash_update($hash, $chunk);
            }
            if ($read !== $shape['size']) {
                throw new \RuntimeException('wprism: media source changed size during bounded hashing');
            }
            self::assertOpenedShape($handle, $shape);
            self::assertNamedShape($path, $shape);
            return hash_final($hash);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{dev:string,ino:string,mode:int,size:int} $shape
     */
    private static function readOpenedFile(string $path, array $shape, ?string $expectedHash): string {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: media source could not be opened for bounded transfer');
        }
        try {
            self::assertOpenedShape($handle, $shape);
            $hash = hash_init('sha256');
            $bytes = '';
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('wprism: media source read failed during bounded transfer');
                }
                if ($chunk === '') {
                    if (!feof($handle)) {
                        throw new \RuntimeException('wprism: media source stalled during bounded transfer');
                    }
                    break;
                }
                if (strlen($bytes) > $shape['size'] - strlen($chunk)) {
                    throw new \RuntimeException('wprism: media source grew during bounded transfer');
                }
                $bytes .= $chunk;
                hash_update($hash, $chunk);
            }
            $actual = hash_final($hash);
            if (strlen($bytes) !== $shape['size']) {
                throw new \RuntimeException('wprism: media source changed size during bounded transfer');
            }
            if ($expectedHash !== null && !hash_equals($expectedHash, $actual)) {
                throw new \RuntimeException('wprism: media source bytes changed after their content observation');
            }
            self::assertOpenedShape($handle, $shape);
            self::assertNamedShape($path, $shape);
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle @param array{dev:string,ino:string,mode:int,size:int} $shape */
    private static function assertOpenedShape($handle, array $shape): void {
        $stat = @fstat($handle);
        if (!is_array($stat)
            || (string) ($stat['dev'] ?? '') !== $shape['dev']
            || (string) ($stat['ino'] ?? '') !== $shape['ino']
            || self::canonicalSize($stat['size'] ?? null) !== $shape['size']
            || ((int) ($stat['mode'] ?? 0)) !== $shape['mode']) {
            throw new \RuntimeException('wprism: media source inode changed during its bounded observation');
        }
    }

    /** @param array{dev:string,ino:string,mode:int,size:int} $shape */
    private static function assertNamedShape(string $path, array $shape): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || is_link($path)
            || (string) ($stat['dev'] ?? '') !== $shape['dev']
            || (string) ($stat['ino'] ?? '') !== $shape['ino']
            || self::canonicalSize($stat['size'] ?? null) !== $shape['size']
            || ((int) ($stat['mode'] ?? 0)) !== $shape['mode']) {
            throw new \RuntimeException('wprism: media source pathname changed during its bounded observation');
        }
    }

    private static function assertFileBytes(int $bytes): void {
        if ($bytes < 0 || $bytes > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('wprism: media payload exceeds its 8 GiB per-file byte authority');
        }
    }

    /**
     * Count JSON container depth and PHP array slots without retaining decoded
     * values. Colons and commas upper-bound valid object/array members even
     * for malformed JSON; Canon::decode() remains the syntax authority.
     *
     * @param array{dev:string,ino:string,mode:int,size:int} $shape
     * @return array{slots:int}
     */
    private static function artifactStructure(string $path, array $shape): array {
        self::assertMemoryHeadroom(self::READ_CHUNK_BYTES, 2, 'compiled artifact structural preflight');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: compiled artifact could not be opened for bounded structural preflight');
        }
        try {
            self::assertOpenedShape($handle, $shape);
            $depth = 0;
            $slots = 1;
            $inString = false;
            $escaped = false;
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('wprism: compiled artifact read failed during bounded structural preflight');
                }
                $length = strlen($chunk);
                for ($offset = 0; $offset < $length; $offset++) {
                    $character = $chunk[$offset];
                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $inString = false;
                        }
                        continue;
                    }
                    if ($character === '"') {
                        $inString = true;
                    } elseif ($character === '{' || $character === '[') {
                        $depth++;
                        if ($depth > self::MAX_ARTIFACT_STRUCTURE_DEPTH) {
                            throw new \RuntimeException('wprism: compiled artifact exceeds its 64-level structural depth authority');
                        }
                    } elseif ($character === '}' || $character === ']') {
                        $depth--;
                    } elseif ($character === ':' || $character === ',') {
                        if ($slots >= self::MAX_ARTIFACT_STRUCTURE_SLOTS) {
                            throw new \RuntimeException(
                                'wprism: compiled artifact exceeds its 16777216-slot bounded decoding authority'
                            );
                        }
                        $slots++;
                    }
                }
            }
            self::assertOpenedShape($handle, $shape);
            self::assertNamedShape($path, $shape);
            return ['slots' => $slots];
        } finally {
            fclose($handle);
        }
    }

    private static function assertDocumentHeadroom(int $documentBytes, int $slots, string $label): void {
        if ($documentBytes < 0
            || $slots < 1
            || $slots > self::MAX_ARTIFACT_STRUCTURE_SLOTS
            || $documentBytes > intdiv(PHP_INT_MAX, 3)
            || $slots > intdiv(PHP_INT_MAX - ($documentBytes * 3), self::ARTIFACT_SLOT_MEMORY_BYTES)) {
            throw new \RuntimeException("wprism: $label has an invalid bounded decoding authority");
        }
        self::assertMemoryBudget(
            ($documentBytes * 3) + ($slots * self::ARTIFACT_SLOT_MEMORY_BYTES),
            $label
        );
    }

    /** @return array{slots:int} */
    private static function documentStringStructure(string $document, string $label): array {
        $depth = 0;
        $slots = 1;
        $inString = false;
        $escaped = false;
        $length = strlen($document);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $document[$offset];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
                if ($depth > self::MAX_ARTIFACT_STRUCTURE_DEPTH) {
                    throw new \RuntimeException("wprism: $label exceeds its 64-level structural depth authority");
                }
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            } elseif ($character === ':' || $character === ',') {
                if ($slots >= self::MAX_ARTIFACT_STRUCTURE_SLOTS) {
                    throw new \RuntimeException("wprism: $label exceeds its 16777216-slot bounded decoding authority");
                }
                $slots++;
            }
        }
        return ['slots' => $slots];
    }

    private static function base64Value(string $character): int {
        $position = strpos(self::BASE64_ALPHABET, $character);
        if (!is_int($position)) {
            throw new \LogicException('wprism: canonical base64 alphabet check lost its validated character');
        }
        return $position;
    }

    private static function canonicalSize(mixed $value): ?int {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($int) ? $int : null;
    }

    private static function assertMemoryHeadroom(int $bytes, int $copies, string $label): void {
        if ($bytes < 0 || $copies <= 0 || $bytes > intdiv(PHP_INT_MAX, $copies)) {
            throw new \RuntimeException("wprism: $label has an invalid memory authority");
        }
        self::assertMemoryBudget(($bytes * $copies), $label);
    }

    private static function assertMemoryBudget(int $neededBytes, string $label): void {
        if ($neededBytes < 0 || $neededBytes > PHP_INT_MAX - self::MEMORY_RESERVE_BYTES) {
            throw new \RuntimeException("wprism: $label has an invalid memory authority");
        }
        $limit = self::memoryLimitBytes((string) ini_get('memory_limit'));
        if ($limit === null) $limit = self::UNLIMITED_MEMORY_FALLBACK_BYTES;
        $used = memory_get_usage(true);
        $needed = $neededBytes + self::MEMORY_RESERVE_BYTES;
        if ($used < 0 || $needed > $limit - min($used, $limit)) {
            throw new \RuntimeException("wprism: $label exceeds the bounded PHP memory headroom");
        }
    }

    private static function memoryLimitBytes(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') return null;
        if (preg_match('/^([0-9]+)([KMG]?)$/iD', $raw, $match) !== 1) return null;
        $number = (int) $match[1];
        $multiplier = match (strtoupper($match[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
        if ($number > intdiv(PHP_INT_MAX, $multiplier)) return null;
        return $number * $multiplier;
    }

    private static function imageContainerIsExact(string $mime, string $bytes): bool {
        $length = strlen($bytes);
        if ($mime === 'image/jpeg') return self::jpegContainerIsExact($bytes);
        if ($mime === 'image/gif') return self::gifContainerIsExact($bytes);
        if ($mime === 'image/webp') {
            if ($length < 12 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') return false;
            $size = unpack('Vsize', substr($bytes, 4, 4));
            if (!is_array($size) || ($size['size'] ?? null) !== $length - 8) return false;
            $offset = 12;
            $sawImage = false;
            while ($offset + 8 <= $length) {
                $type = substr($bytes, $offset, 4);
                $decoded = unpack('Vsize', substr($bytes, $offset + 4, 4));
                $chunkSize = is_array($decoded) ? ($decoded['size'] ?? null) : null;
                if (!is_int($chunkSize) || $chunkSize < 0 || $chunkSize > $length - $offset - 8) return false;
                if (in_array($type, ['VP8 ', 'VP8L'], true)) $sawImage = true;
                $offset += 8 + $chunkSize + ($chunkSize & 1);
            }
            return $sawImage && $offset === $length;
        }
        if ($mime !== 'image/png' || $length < 20 || !str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) return false;
        $offset = 8;
        $sawHeader = false;
        $sawData = false;
        while ($offset + 12 <= $length) {
            $decoded = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($decoded) ? ($decoded['length'] ?? null) : null;
            if (!is_int($chunkLength) || $chunkLength < 0 || $chunkLength > $length - $offset - 12) return false;
            $type = substr($bytes, $offset + 4, 4);
            $chunk = substr($bytes, $offset + 4, 4 + $chunkLength);
            $declaredCrc = unpack('Ncrc', substr($bytes, $offset + 8 + $chunkLength, 4));
            $actualCrc = unpack('Ncrc', hash('crc32b', $chunk, true));
            if (!is_array($declaredCrc)
                || !is_array($actualCrc)
                || ($declaredCrc['crc'] ?? null) !== ($actualCrc['crc'] ?? null)) return false;
            if (!$sawHeader) {
                if ($type !== 'IHDR' || $chunkLength !== 13) return false;
                $header = unpack(
                    'Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace',
                    substr($bytes, $offset + 8, 13)
                );
                if (!is_array($header)
                    || !is_int($header['width'] ?? null)
                    || !is_int($header['height'] ?? null)
                    || $header['width'] <= 0
                    || $header['height'] <= 0
                    || ($header['compression'] ?? null) !== 0
                    || ($header['filter'] ?? null) !== 0
                    || !in_array($header['interlace'] ?? null, [0, 1], true)) return false;
                $sawHeader = true;
            } elseif ($type === 'IHDR') {
                return false;
            }
            if ($type === 'IDAT') $sawData = true;
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') {
                return $sawHeader && $sawData && $chunkLength === 0 && $offset === $length;
            }
        }
        return false;
    }

    private static function assertRasterDecodable(string $mime, string $bytes): void {
        if (!function_exists('getimagesizefromstring') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('wprism: media raster authority requires the reviewed GD decoder');
        }
        $dimensions = @getimagesizefromstring($bytes);
        $width = is_array($dimensions) ? ($dimensions[0] ?? null) : null;
        $height = is_array($dimensions) ? ($dimensions[1] ?? null) : null;
        $detected = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
        if (!is_int($width)
            || !is_int($height)
            || $width <= 0
            || $height <= 0
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || $width > intdiv(self::MAX_SOURCE_PIXELS, $height)
            || !is_string($detected)
            || !hash_equals($mime, $detected)) {
            throw new \RuntimeException('wprism: media raster dimensions or decoder MIME exceed the bounded authority');
        }
        $pixels = $width * $height;
        self::assertMemoryHeadroom($pixels * 4, 2, 'media raster GD decode');
        $image = @imagecreatefromstring($bytes);
        if (!is_object($image) && !is_resource($image)) {
            throw new \RuntimeException('wprism: media raster bytes are not decodable by the reviewed GD path');
        }
        unset($image);
    }

    private static function jpegContainerIsExact(string $bytes): bool {
        $length = strlen($bytes);
        if ($length < 4 || !str_starts_with($bytes, "\xFF\xD8")) return false;
        $offset = 2;
        $sawFrame = false;
        $sawScan = false;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xFF) return false;
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) ++$offset;
            if ($offset >= $length) return false;
            $marker = ord($bytes[$offset++]);
            if ($marker === 0x00) return false;
            if ($marker === 0xD9) return $sawFrame && $sawScan && $offset === $length;
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) return false;
            if ($offset + 2 > $length) return false;
            $decoded = unpack('nlength', substr($bytes, $offset, 2));
            $segmentLength = is_array($decoded) ? ($decoded['length'] ?? null) : null;
            if (!is_int($segmentLength) || $segmentLength < 2 || $segmentLength > $length - $offset) return false;
            if (in_array($marker, [
                0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7,
                0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF,
            ], true)) $sawFrame = true;
            $offset += $segmentLength;
            if ($marker !== 0xDA) continue;
            $sawScan = true;
            while ($offset < $length) {
                if (ord($bytes[$offset]) !== 0xFF) {
                    ++$offset;
                    continue;
                }
                $markerOffset = $offset;
                while ($offset < $length && ord($bytes[$offset]) === 0xFF) ++$offset;
                if ($offset >= $length) return false;
                $entropyMarker = ord($bytes[$offset++]);
                if ($entropyMarker === 0x00 || ($entropyMarker >= 0xD0 && $entropyMarker <= 0xD7)) continue;
                $offset = $markerOffset;
                break;
            }
        }
        return false;
    }

    private static function gifContainerIsExact(string $bytes): bool {
        $length = strlen($bytes);
        if ($length < 14 || !(str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'))) return false;
        $offset = 13;
        $packed = ord($bytes[10]);
        if (($packed & 0x80) !== 0) $offset += 3 * (1 << (($packed & 0x07) + 1));
        if ($offset >= $length) return false;
        $sawImage = false;
        while ($offset < $length) {
            $introducer = ord($bytes[$offset++]);
            if ($introducer === 0x3B) return $sawImage && $offset === $length;
            if ($introducer === 0x21) {
                if ($offset >= $length) return false;
                ++$offset;
                $offset = self::gifSubBlocksEnd($bytes, $offset, $extensionData);
                if ($offset < 0) return false;
                continue;
            }
            if ($introducer !== 0x2C || $offset + 9 > $length) return false;
            $descriptorPacked = ord($bytes[$offset + 8]);
            $offset += 9;
            if (($descriptorPacked & 0x80) !== 0) $offset += 3 * (1 << (($descriptorPacked & 0x07) + 1));
            if ($offset >= $length) return false;
            $codeSize = ord($bytes[$offset++]);
            if ($codeSize < 2 || $codeSize > 12) return false;
            $offset = self::gifSubBlocksEnd($bytes, $offset, $imageData);
            if ($offset < 0 || !$imageData) return false;
            $sawImage = true;
        }
        return false;
    }

    private static function gifSubBlocksEnd(string $bytes, int $offset, ?bool &$hadData): int {
        $length = strlen($bytes);
        $hadData = false;
        while ($offset < $length) {
            $size = ord($bytes[$offset++]);
            if ($size === 0) return $offset;
            $hadData = true;
            if ($size > $length - $offset) return -1;
            $offset += $size;
        }
        return -1;
    }
}
