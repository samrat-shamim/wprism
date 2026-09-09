<?php
namespace WPrism;

// Deliberately NOT require_once('Canon.php') or require_once('Code.php') here,
// matching the pre-extraction file exactly: CompiledRepository has always
// called Canon::encode()/write_file() and Code::assert_descriptor() without
// requiring either file itself, relying on a caller (agent/wprism.php's bootstrap,
// or a test's own hand-picked require list) to have loaded them first.
// sandbox/tests/offline/cli/regress_cli_json_refusals.php depends on exactly this laxity
// for Canon — it stubs a fake WPrism\Canon and requires RepositoryCompiler.php
// (hence this file) without ever loading the real Canon.php; requiring it here
// fatals that suite with "Cannot redeclare class WPrism\Canon" (caught by
// regress-offline-all during this slice's own verification). No current caller
// stubs Code the same way, but the risk is identical in kind, so this file
// stays symmetric about both rather than requiring one and not the other. Any
// caller that needs Canon or Code (like this file's own test) must require
// them explicitly itself.
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';
require_once __DIR__ . '/../Kernel/MediaDerivativeRecipe.php';

/**
 * One immutable, typed result of compiling a repository revision. The
 * constructor is private on purpose: plan/apply/deploy can receive this
 * object, never an arbitrary array assembled by a caller that skipped the
 * compiler. PHP arrays are copy-on-write, so returning tree() cannot mutate
 * the object held by another phase of the same command.
 *
 * Extracted from RepositoryCompiler.php (issue #3348 slice 2, the "CompiledArtifact
 * value object" target seam) — CompiledRepository and RepositoryCompilationException
 * were already self-contained, standalone classes sharing no mutable state with
 * the RepositoryCompiler builder beyond being constructed/returned by it, so this
 * is a pure file relocation with no logic changes. RepositoryCompiler.php keeps a
 * require_once for this file so its own ~25 existing external callers (agent/src
 * and sandbox/tests alike, most of which require RepositoryCompiler.php directly
 * expecting CompiledRepository to come along transitively) need no change.
 */
final class CompiledRepository {
    public const FORMAT = 'wprism-compiled-repository/v1';

    private array $artifact;
    private ?string $mediaDirectory;
    /** @var array<string,true> one aggregate accounting entry per immutable blob */
    private array $decodedMedia = [];
    private int $decodedMediaBytes = 0;

    private function __construct(array $artifact, ?string $mediaDirectory = null) {
        $this->artifact = $artifact;
        $this->mediaDirectory = $mediaDirectory === null ? null : rtrim($mediaDirectory, '/');
    }

    public static function create(array $payload, ?string $mediaDirectory = null): self {
        if (array_key_exists('code', $payload)) {
            if (!is_array($payload['code'])) {
                throw new \RuntimeException('wprism: compiled code descriptor must be an object');
            }
            Code::assert_descriptor($payload['code']);
        }
        if (!is_array($payload['tree'] ?? null)) {
            throw new \RuntimeException('wprism: compiled repository payload has no typed tree');
        }
        // Hand-built typed artifacts predate repository media. Keep their
        // state-only shape readable while every compiler-produced artifact
        // still supplies its explicit bounded map below.
        $payload['media'] ??= [];
        if (!is_array($payload['media'])) {
            throw new \RuntimeException('wprism: compiled repository payload has no typed media');
        }
        $payload['effects_inventory'] ??= [];
        if (!is_array($payload['effects_inventory'])
            || !array_is_list($payload['effects_inventory'])) {
            throw new \RuntimeException('wprism: compiled repository payload has no effects inventory');
        }
        MediaDerivativeRecipe::assert_inventory($payload['media_derivatives'] ?? [], $payload['tree']);
        $payload['uploads_inventory'] = self::derive_uploads_inventory($payload['tree']);
        MediaPayloadAuthority::assertArtifactMedia($payload['media'], $payload['tree'], $mediaDirectory);
        $payload['format'] = self::FORMAT;
        $payload['artifact_hash'] = self::content_hash($payload);
        return new self($payload, $mediaDirectory);
    }

    public static function from_array(array $artifact, ?string $mediaDirectory = null): self {
        if (!is_array($artifact['tree'] ?? null) || !is_array($artifact['media'] ?? null)) {
            throw new \RuntimeException('wprism: compiled artifact has no typed tree or media');
        }
        // Validate encoded media before Canon::encode() re-materializes the
        // whole artifact for its self-hash. This keeps a hostile base64 row
        // behind the same decoded-length and aggregate authority as compile.
        MediaPayloadAuthority::assertArtifactMedia($artifact['media'], $artifact['tree'], $mediaDirectory);
        $actual = (string) ($artifact['artifact_hash'] ?? '');
        $copy = $artifact;
        unset($copy['artifact_hash']);
        if (($artifact['format'] ?? '') !== self::FORMAT
            || !preg_match('/^[0-9a-f]{64}$/', $actual)
            || !hash_equals(self::content_hash($copy), $actual)) {
            throw new \RuntimeException('wprism: compiled artifact is malformed or its content hash does not verify');
        }
        if (($artifact['uploads_inventory'] ?? null) !== self::derive_uploads_inventory($artifact['tree'])) {
            throw new \RuntimeException('wprism: compiled artifact upload inventory does not match its typed tree');
        }
        MediaDerivativeRecipe::assert_inventory($artifact['media_derivatives'] ?? [], $artifact['tree']);
        if (array_key_exists('code', $artifact)) {
            if (!is_array($artifact['code'])) {
                throw new \RuntimeException('wprism: compiled artifact code descriptor is malformed');
            }
            Code::assert_descriptor($artifact['code']);
        }
        return new self($artifact, $mediaDirectory);
    }

    private static function content_hash(array $payload): string {
        unset($payload['artifact_hash']);
        return hash('sha256', Canon::encode($payload));
    }

    public function tree(): array {
        return $this->artifact['tree'];
    }

    /** @return array<string,array<string,mixed>> keyed by deleted UUID */
    public function deletions(): array {
        return (array) ($this->artifact['deletions'] ?? []);
    }

    public function artifact_hash(): string {
        return $this->artifact['artifact_hash'];
    }

    public function revision_hash(): string {
        return $this->artifact['revision_hash'];
    }

    /** @return ?array<string,mixed> */
    public function code_descriptor(): ?array {
        $descriptor = $this->artifact['code'] ?? null;
        return is_array($descriptor) ? $descriptor : null;
    }

    public function code_revision(): ?string {
        $descriptor = $this->code_descriptor();
        return $descriptor === null ? null : (string) ($descriptor['code_revision'] ?? '');
    }

    public function manifest_hash(): string {
        return $this->artifact['manifest_hash'];
    }

    /**
     * issue #3222: the resolved adapter compatibility contract this artifact
     * was compiled against — one row per pinned manifest (name, per-
     * manifest digest, spec_version if declared, plugin/version_range or
     * theme/theme_version_range if declared). Policy declaration validity
     * and source header compatibility are checked offline by
     * CodeCompatibility during compilation; the source check is repeated by
     * Code::stage under the target lease. This field still records only the
     * manifest declarations: it does not persist header facts or claim a
     * live-environment match. Compilation remains target-DB-free (no $wpdb,
     * get_plugins(), or wp_get_theme() calls), while Deploy::code_mismatch()
     * remains the independent proof of what is installed on the target.
     * issue #3227's generated capability claim is now carried beside exactly
     * this shape (one machine-readable row per adapter); its digest remains
     * the digest of manifest+interpreter+manifest-sourced-provider+
     * regenerator+external disposition (issue #3338 folded provider file bytes in
     * beside the interpreter's, issue #3360 the regenerator's), avoiding a
     * self-referential hash while the outer artifact binds the claim too.
     *
     * @return list<array{name:string, digest:string, spec_version:?int, plugin:?string, version_range:?array, theme:?string, theme_version_range:?array, disposition:?array, capability:?array}>
     */
    public function resolved_adapters(): array {
        return $this->artifact['resolved_adapters'] ?? [];
    }

    public function site_hash(): string {
        return $this->artifact['site_hash'];
    }

    public function media_content(string $name): string {
        $row = $this->artifact['media'][$name] ?? null;
        if (!is_array($row) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException("wprism: compiled artifact has no media payload '$name'");
        }
        if (($row['source'] ?? null) === 'repository') {
            if ($this->mediaDirectory === null || !is_int($row['size'] ?? null)) {
                throw new \RuntimeException("wprism: compiled external media '$name' has no repository source");
            }
            $parsed = MediaPayloadAuthority::parseMediaName($name);
            $witness = [
                'extension' => $parsed['extension'],
                'sha256' => $parsed['sha256'],
                'size' => $row['size'],
            ];
            $bytes = MediaPayloadAuthority::readFile($this->mediaDirectory . '/' . $name, $witness);
        } else {
        /** @var array{sha256:string,base64:string} $row */
            $bytes = MediaPayloadAuthority::decodeArtifactMedia($name, $row);
        }
        if (!isset($this->decodedMedia[$name])) {
            $this->decodedMediaBytes = MediaPayloadAuthority::addToAggregate(
                $this->decodedMediaBytes,
                strlen($bytes)
            );
            $this->decodedMedia[$name] = true;
        }
        return $bytes;
    }

    public function media_size(string $name): int {
        $row = $this->artifact['media'][$name] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException("wprism: compiled artifact has no media payload '$name'");
        }
        if (($row['source'] ?? null) === 'repository' && is_int($row['size'] ?? null)) {
            return $row['size'];
        }
        if (is_string($row['base64'] ?? null)) {
            return MediaPayloadAuthority::canonicalBase64DecodedLength(
                $row['base64'],
                'compiled artifact media size'
            );
        }
        throw new \RuntimeException("wprism: compiled artifact media payload '$name' is malformed");
    }

    public function media_sha256(string $name): string {
        $row = $this->artifact['media'][$name] ?? null;
        if (!is_array($row) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException("wprism: compiled artifact has no media payload '$name'");
        }
        return $row['sha256'];
    }

    /** @param resource $output */
    public function copy_media_to_stream(string $name, $output): void {
        $row = $this->artifact['media'][$name] ?? null;
        if (!is_array($row) || !is_resource($output)) {
            throw new \RuntimeException("wprism: compiled artifact media transfer '$name' is malformed");
        }
        if (($row['source'] ?? null) === 'repository') {
            if ($this->mediaDirectory === null || !is_int($row['size'] ?? null)) {
                throw new \RuntimeException("wprism: compiled external media '$name' has no repository source");
            }
            $parsed = MediaPayloadAuthority::parseMediaName($name);
            MediaPayloadAuthority::copyFileToStream(
                $this->mediaDirectory . '/' . $name,
                ['extension' => $parsed['extension'], 'sha256' => $row['sha256'], 'size' => $row['size']],
                $output
            );
            return;
        }
        $bytes = MediaPayloadAuthority::decodeArtifactMedia($name, $row);
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($output, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new \RuntimeException('wprism: compiled inline media transfer failed');
            }
            $offset += $written;
        }
    }

    /**
     * Immutable, target-independent upload mutation declaration. Each row
     * names the repository-owned original and the only directory/prefix in
     * which WordPress metadata generation may report derivatives for it.
     * Runtime preparation turns this bounded declaration into exact
     * before-images/absence receipts before any target write.
     *
     * @return list<array<string,string>>
     */
    public function uploads_inventory(): array {
        return (array) ($this->artifact['uploads_inventory'] ?? []);
    }

    /** Exact content-selected derivative recipes; absent for legacy artifacts. */
    public function media_derivatives(): array {
        return $this->artifact['media_derivatives'] ?? [];
    }

    /** Complete manifest-bound lifecycle/rebuild effect declaration. */
    public function effects_inventory(): array {
        return (array) ($this->artifact['effects_inventory'] ?? []);
    }

    /** @return list<array<string,string>> */
    private static function derive_uploads_inventory(array $tree): array {
        $rows = [];
        foreach ($tree as $uuid => $entry) {
            $front = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            if (($entry['type'] ?? '') !== 'post' || ($front['type'] ?? '') !== 'attachment') {
                continue;
            }
            $path = (string) ($front['file'] ?? '');
            $media = (string) ($front['media'] ?? '');
            $directory = dirname($path);
            if ($directory === '.') {
                $directory = '';
            }
            $rows[] = [
                'attachment_uuid' => (string) $uuid,
                'derivative_basename_prefix' => pathinfo(basename($path), PATHINFO_FILENAME) . '-',
                'derivative_directory' => $directory,
                'media_blob' => $media,
                'original_path' => $path,
                'original_sha256' => preg_match('/^([0-9a-f]{64})\./', $media, $m) === 1 ? $m[1] : '',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['original_path'], $b['original_path']));
        return $rows;
    }

    public function export(): array {
        return $this->artifact;
    }

    public function write(string $path): void {
        Canon::write_file($path, Canon::encode($this->artifact));
    }
}

/** Stable, batched failure returned by the offline compiler. */
final class RepositoryCompilationException extends \RuntimeException {
    /** @var array<int,array<string,mixed>> */
    public array $diagnostics;

    public function __construct(array $diagnostics) {
        $this->diagnostics = $diagnostics;
        $lines = array_map(static function (array $d): string {
            $where = $d['path'] . (($d['locator'] ?? '') !== '' ? ':' . $d['locator'] : '');
            return '[' . $d['code'] . '] ' . $where . ' — ' . $d['message'];
        }, $diagnostics);
        parent::__construct(
            'wprism: repository compilation failed (' . count($diagnostics)
            . " blocking diagnostic(s)); no target contact or mutation attempted:\n  - "
            . implode("\n  - ", $lines)
        );
    }

    public function payload(): array {
        return [
            'ok' => false,
            'error' => 'repository_compilation_failed',
            'diagnostics' => $this->diagnostics,
        ];
    }
}
