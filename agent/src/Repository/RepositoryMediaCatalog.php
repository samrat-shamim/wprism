<?php
namespace Duo;

// Repository media validation reads immutable canonical files and does not
// need the compiler's repository tree, Policy, or any target-facing API.
// Keep the compiler's established refusal-test seam: direct normal loads
// need Canon, but a preloaded Canon stub must not be redeclared while a
// target-free CLI refusal is being rendered. This mirrors the identity
// collaborator's direct-load boundary without making this catalog depend on
// compiler bootstrap order.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';

/**
 * Validates repository-owned attachment payloads and the complete media/
 * partition while preserving RepositoryCompiler's one diagnostic accumulator.
 *
 * The compiler constructs this once with its resolved media root and gives it
 * the existing add() sink. This keeps media findings in the historical global
 * sorted refusal and lets compilation continue long enough to report every
 * independent malformed path; this object must never throw a partial media
 * result or contact WordPress to infer an upload location.
 */
final class RepositoryMediaCatalog {
    private string $mediaDir;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;
    /** @var array<string,string> upload-relative path => source path */
    private array $uploadPaths = [];
    /** @var array<string,string> derivative directory/basename prefix => source path */
    private array $uploadDerivativeRoots = [];
    /** @var array<string,array{path:string,witness:array{extension:string,sha256:string,size:int}}> */
    private array $media = [];
    /** @var array<string,string> every content-addressed blob in media/, including safe orphans */
    private array $catalog = [];
    private int $catalogBytes = 0;
    private int $catalogFiles = 0;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(string $mediaDir, \Closure $add) {
        $this->mediaDir = rtrim($mediaDir, '/');
        $this->add = $add;
    }

    /** @param array<string,mixed> $data */
    public function validate_attachment(string $path, array $data): void {
        if (($data['type'] ?? '') !== 'attachment') {
            return;
        }
        foreach (['file','media','mime','alt'] as $field) {
            if (!array_key_exists($field, $data) || !is_string($data[$field])) {
                $this->add('schema_content_mismatch', $path, $field, 'attachment field is required and must be a string');
            }
        }
        $upload = (string) ($data['file'] ?? '');
        // WordPress normally stores Y/m/basename, but plugins legitimately
        // create upload-root files (WooCommerce's placeholder is the
        // canonical example) and may use their own safe subdirectories.
        // The portable invariant is a normalized relative path, not one
        // particular directory policy.
        try {
            MediaPayloadAuthority::assertRelativeUploadPath($upload);
            $safeUpload = true;
        } catch (\Throwable $failure) {
            $safeUpload = false;
            $this->add('unsafe_media_path', $path, 'file', "upload path '$upload' is not a normalized relative path");
        }
        if ($safeUpload && isset($this->uploadPaths[$upload])) {
            $this->add('duplicate_upload_path', $path, 'file', "upload path '$upload' is also owned by {$this->uploadPaths[$upload]}", $this->uploadPaths[$upload]);
        } elseif ($safeUpload) {
            $this->uploadPaths[$upload] = $path;
            $directory = dirname($upload);
            $root = ($directory === '.' ? '' : $directory . '/')
                . pathinfo(basename($upload), PATHINFO_FILENAME) . '-';
            if (isset($this->uploadDerivativeRoots[$root])) {
                $this->add(
                    'duplicate_media_derivative_root',
                    $path,
                    'file',
                    "upload path '$upload' shares derivative root '$root*' with {$this->uploadDerivativeRoots[$root]}",
                    $this->uploadDerivativeRoots[$root]
                );
            } else {
                $this->uploadDerivativeRoots[$root] = $path;
            }
        }
        $blob = (string) ($data['media'] ?? '');
        try {
            MediaPayloadAuthority::parseMediaName($blob);
        } catch (\Throwable $failure) {
            $this->add('unsafe_media_path', $path, 'media', "media reference '$blob' is not content-addressed");
            return;
        }
        $absolute = $this->mediaDir . '/' . $blob;
        if (is_link($absolute)) {
            $this->add('unsafe_media_path', $path, 'media', "media/$blob is a symbolic link");
            return;
        }
        if (!is_file($absolute)) {
            $this->add('missing_media_blob', $path, 'media', "media/$blob does not exist");
            return;
        }
        try {
            $absolute = MediaPayloadAuthority::physicalLocalFilePath($absolute);
            MediaPayloadAuthority::observeCatalogFile($absolute, $blob);
        } catch (\Throwable $failure) {
            $this->add('media_hash_mismatch', $path, 'media', $failure->getMessage());
            return;
        }
        try {
            $witness = MediaPayloadAuthority::observeFile(
                $absolute,
                $upload,
                (string) ($data['mime'] ?? '')
            );
            MediaPayloadAuthority::assertMediaName($blob, $witness);
        } catch (\Throwable $failure) {
            $this->add('invalid_media_payload', $path, 'media', $failure->getMessage());
            return;
        }
        if (isset($this->media[$blob]) && $this->media[$blob]['witness'] !== $witness) {
            $this->add(
                'invalid_media_payload',
                $path,
                'media',
                "media/$blob is referenced with an inconsistent immutable blob witness"
            );
            return;
        }
        $this->media[$blob] = ['path' => $absolute, 'witness' => $witness];
    }

    /** Hash the whole media partition, including safe orphan blobs. */
    public function catalog_directory(): void {
        if (!is_dir($this->mediaDir)) {
            return;
        }
        foreach (new \FilesystemIterator($this->mediaDir, \FilesystemIterator::SKIP_DOTS) as $file) {
            $this->catalogFiles++;
            if ($this->catalogFiles > MediaPayloadAuthority::MAX_CATALOG_FILES) {
                $this->add(
                    'media_catalog_oversized',
                    'media',
                    '',
                    'media catalog exceeds its bounded entry observation authority'
                );
                break;
            }
            try {
                MediaPayloadAuthority::assertCatalogMapHeadroom($this->catalogFiles);
            } catch (\Throwable $failure) {
                $this->add('media_catalog_oversized', 'media', '', $failure->getMessage());
                break;
            }
            $name = $file->getFilename();
            $path = 'media/' . $name;
            if ($file->isLink() || !$file->isFile()) {
                $this->add('unsafe_media_path', $path, '', 'media entries must be regular files directly under media/');
                continue;
            }
            try {
                MediaPayloadAuthority::parseMediaName($name);
            } catch (\Throwable $failure) {
                $this->add('unsafe_media_path', $path, '', 'media filename is not content-addressed');
                continue;
            }
            try {
                $observed = MediaPayloadAuthority::observeCatalogFile($file->getPathname(), $name);
                $this->catalogBytes = MediaPayloadAuthority::addToAggregate(
                    $this->catalogBytes,
                    $observed['size']
                );
            } catch (\Throwable $failure) {
                $this->add('invalid_media_payload', $path, '', $failure->getMessage());
                continue;
            }
            $this->catalog[$name] = $observed['sha256'];
        }
        ksort($this->catalog, SORT_STRING);
    }

    /** @return array<string,array{sha256:string,base64:string}> */
    public function referenced_media(): array {
        $bytes = 0;
        foreach ($this->media as $source) {
            $bytes = MediaPayloadAuthority::addToAggregate($bytes, $source['witness']['size']);
        }
        MediaPayloadAuthority::assertArtifactHeadroom($bytes);
        ksort($this->media, SORT_STRING);
        $out = [];
        foreach ($this->media as $name => $source) {
            $payload = MediaPayloadAuthority::readFile($source['path'], $source['witness']);
            MediaPayloadAuthority::assertMediaName($name, $source['witness']);
            $out[$name] = [
                'sha256' => $source['witness']['sha256'],
                'base64' => base64_encode($payload),
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    public function catalog(): array {
        ksort($this->catalog, SORT_STRING);
        return $this->catalog;
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
