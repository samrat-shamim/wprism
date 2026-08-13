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
    require_once __DIR__ . '/Canon.php';
}

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
    /** @var array<string,array{sha256:string,base64:string}> media blob => immutable payload */
    private array $media = [];
    /** @var array<string,string> every content-addressed blob in media/, including safe orphans */
    private array $catalog = [];

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
        $segments = explode('/', $upload);
        $safeUpload = $upload !== ''
            && !str_starts_with($upload, '/')
            && !str_contains($upload, '\\')
            && !preg_match('/[\x00-\x1f\x7f]/', $upload)
            && !array_filter($segments, static fn(string $part): bool => $part === '' || $part === '.' || $part === '..');
        if (!$safeUpload) {
            $this->add('unsafe_media_path', $path, 'file', "upload path '$upload' is not a normalized relative path");
        } elseif (isset($this->uploadPaths[$upload])) {
            $this->add('duplicate_upload_path', $path, 'file', "upload path '$upload' is also owned by {$this->uploadPaths[$upload]}", $this->uploadPaths[$upload]);
        } else {
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
        if (!preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', $blob, $m)) {
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
        $bytes = Canon::read_file($absolute);
        $actual = hash('sha256', $bytes);
        if (!hash_equals($m[1], $actual)) {
            $this->add('media_hash_mismatch', $path, 'media', "media/$blob hashes to $actual");
            return;
        }
        $this->media[$blob] = ['sha256' => $actual, 'base64' => base64_encode($bytes)];
    }

    /** Hash the whole media partition, including safe orphan blobs. */
    public function catalog_directory(): void {
        if (!is_dir($this->mediaDir)) {
            return;
        }
        foreach (new \FilesystemIterator($this->mediaDir, \FilesystemIterator::SKIP_DOTS) as $file) {
            $name = $file->getFilename();
            $path = 'media/' . $name;
            if ($file->isLink() || !$file->isFile()) {
                $this->add('unsafe_media_path', $path, '', 'media entries must be regular files directly under media/');
                continue;
            }
            if (!preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', $name, $m)) {
                $this->add('unsafe_media_path', $path, '', 'media filename is not content-addressed');
                continue;
            }
            $actual = hash_file('sha256', $file->getPathname());
            if (!hash_equals($m[1], $actual)) {
                $this->add('media_hash_mismatch', $path, '', "filename hash does not match $actual");
                continue;
            }
            $this->catalog[$name] = $actual;
        }
        ksort($this->catalog, SORT_STRING);
    }

    /** @return array<string,array{sha256:string,base64:string}> */
    public function referenced_media(): array {
        ksort($this->media, SORT_STRING);
        return $this->media;
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
