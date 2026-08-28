<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/AdapterPackageProjection.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';

/**
 * Assemble the allowlisted adapter library inside a disposable agent stage.
 *
 * The complete tree is built and verified in a sibling directory before one
 * rename publishes it. Existing staging output is held as a rollback backup
 * until that rename succeeds, so the final path never exposes a partly copied
 * library and a failed publish restores the caller's prior staging state.
 */
final class AdapterLibraryAssembler
{
    public const FORMAT = 'duo-embedded-adapter-library-assembly/v1';
    public const DEPLOYMENT_MARKER = 'adapter-library.deployed';

    private const DEPLOYMENT_MARKER_BYTES = "duo-embedded-adapter-library-assembly/v1\n";

    private const DIRECTORY_MODE = 0040000;
    private const FILE_MODE = 0100000;
    private const TYPE_MODE = 0170000;
    private const OUTPUT_DIRECTORY_PERMISSIONS = 0755;
    private const OUTPUT_FILE_PERMISSIONS = 0644;
    private const OUTPUT_MTIME = 946684800; // 2000-01-01T00:00:00Z.

    /**
     * @return array{
     *     format:string,
     *     target:string,
     *     library_sha256:string,
     *     files:list<array{path:string,sha256:string,size:int}>
     * }
     * @param null|callable():void $afterSourceGenerationCaptured deterministic concurrency-test seam
     * @param null|callable(string):void $publicationCheckpoint deterministic publication-test seam
     */
    public static function assemble(
        string $repoRoot,
        string $stagingAgentRoot,
        ?callable $afterSourceGenerationCaptured = null,
        ?callable $publicationCheckpoint = null
    ): array
    {
        // Plan before making any staging mutation. Source schema, symlink,
        // special-node and undeclared-runtime refusals therefore leave an
        // existing staged library byte-for-byte untouched.
        $plan = AdapterPackageProjection::plan($repoRoot);
        $repository = self::ordinaryDirectory($repoRoot, 'repository root');
        $agent = self::ordinaryDirectory($stagingAgentRoot, 'staging agent root');
        if ($agent === $repository || str_starts_with($agent, $repository . '/')) {
            throw new RuntimeException(
                'Staging agent root must be outside the source repository; agent/adapter-library is deployment output'
            );
        }

        $parent = self::ordinaryDirectory(dirname($agent), 'staging agent parent');
        if (!is_writable($parent) || !is_writable($agent)) {
            throw new RuntimeException('Staging agent root and its parent must be writable');
        }

        $target = $agent . '/adapter-library';
        self::assertReplaceableTarget($target, $agent);
        $generation = self::captureSourceGeneration($plan);
        if ($afterSourceGenerationCaptured !== null) {
            $afterSourceGenerationCaptured();
        }
        $token = bin2hex(random_bytes(12));
        $prefix = '.' . basename($agent) . '.adapter-library-';
        $build = $parent . '/' . $prefix . 'build-' . $token;
        $backup = $parent . '/' . $prefix . 'backup-' . $token;
        if (!mkdir($build, self::OUTPUT_DIRECTORY_PERMISSIONS)) {
            throw new RuntimeException("Cannot create adapter-library assembly directory: $build");
        }

        $published = false;
        try {
            $rows = self::populate($plan, $generation, $build);
            self::verifyTree($build, $rows);
            self::normalizeDirectories($build);
            self::assertSourceGenerationUnchanged($repoRoot, $plan, $generation);
            self::validateEmbeddedLibrary($build, $agent);
            // The marker and library are one deployed authority boundary. No
            // package source is read after this point, so every source refusal
            // leaves both prior nodes untouched; marker publication still
            // precedes the target rename so a deployed library is never
            // published without its authority marker.
            self::publish($build, $target, $backup, $agent, $publicationCheckpoint);
            $published = true;

            return [
                'format' => self::FORMAT,
                'target' => $target,
                'library_sha256' => self::libraryDigest($rows),
                'files' => $rows,
            ];
        } catch (Throwable $throwable) {
            if (!$published && self::nodeExists($build)) {
                self::removeOwnedTree($build, $parent);
            }
            throw $throwable;
        }
    }

    /** Validate the exact projected layout through the production runtime reader before publication. */
    private static function validateEmbeddedLibrary(string $build, string $agent): void
    {
        \Duo\AdapterLibrary::fromEmbeddedDirectory(
            $build,
            dirname($agent) . '/duo-control/adapter-revocations.json'
        );
    }

    /**
     * @param array<string,string> $plan
     * @param array<string,array{bytes:string,sha256:string,size:int}> $generation
     * @return list<array{path:string,sha256:string,size:int}>
     */
    private static function populate(array $plan, array $generation, string $build): array
    {
        $rows = [];
        foreach ($plan as $source => $destination) {
            $relative = self::libraryRelativePath($destination);
            $output = $build . '/' . $relative;
            self::makeParents($build, dirname($relative));
            $bytes = $generation[$source]['bytes'];
            self::writeExclusive($output, $bytes);
            $digest = $generation[$source]['sha256'];
            if (!hash_equals($digest, (string) hash_file('sha256', $output))) {
                throw new RuntimeException("Copied adapter-library member failed byte verification: $destination");
            }
            $rows[] = [
                'path' => $destination,
                'sha256' => $digest,
                'size' => strlen($bytes),
            ];
        }

        return $rows;
    }

    /**
     * Capture every projected member before copying any of them. A second full
     * pass immediately before publish proves the output is one source
     * generation rather than individually stable bytes from several edits.
     *
     * @param array<string,string> $plan
     * @return array<string,array{bytes:string,sha256:string,size:int}>
     */
    private static function captureSourceGeneration(array $plan): array
    {
        $generation = [];
        foreach ($plan as $source => $_destination) {
            $bytes = self::readStableSource($source);
            $generation[$source] = [
                'bytes' => $bytes,
                'sha256' => hash('sha256', $bytes),
                'size' => strlen($bytes),
            ];
        }

        return $generation;
    }

    /**
     * @param array<string,string> $plan
     * @param array<string,array{bytes:string,sha256:string,size:int}> $generation
     */
    private static function assertSourceGenerationUnchanged(
        string $repoRoot,
        array $plan,
        array $generation
    ): void
    {
        try {
            $currentPlan = AdapterPackageProjection::plan($repoRoot);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Adapter package source generation changed during assembly',
                0,
                $throwable
            );
        }
        if ($currentPlan !== $plan) {
            throw new RuntimeException('Adapter package source generation changed during assembly');
        }
        foreach ($plan as $source => $_destination) {
            $bytes = self::readStableSource($source);
            if (strlen($bytes) !== $generation[$source]['size']
                || !hash_equals(hash('sha256', $bytes), $generation[$source]['sha256'])) {
                throw new RuntimeException("Adapter package source generation changed during assembly: $source");
            }
        }
        try {
            $finalPlan = AdapterPackageProjection::plan($repoRoot);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Adapter package source generation changed during assembly',
                0,
                $throwable
            );
        }
        if ($finalPlan !== $plan) {
            throw new RuntimeException('Adapter package source generation changed during assembly');
        }
    }

    /** Return true only when this call created the marker and therefore owns rollback of it. */
    private static function writeDeploymentMarker(string $agent): bool
    {
        $marker = $agent . '/' . self::DEPLOYMENT_MARKER;
        if (self::nodeExists($marker)) {
            $stat = @lstat($marker);
            if ($stat === false
                || ($stat['mode'] & self::TYPE_MODE) !== self::FILE_MODE
                || is_link($marker)
                || file_get_contents($marker) !== self::DEPLOYMENT_MARKER_BYTES) {
                throw new RuntimeException("Staging agent has an invalid adapter-library deployment marker: $marker");
            }
            return false;
        }
        // writeExclusive() cleans up only after its own exclusive open
        // succeeds; an open collision belongs to another writer and is never
        // unlinked here.
        self::writeExclusive($marker, self::DEPLOYMENT_MARKER_BYTES);

        return true;
    }

    private static function libraryRelativePath(string $destination): string
    {
        $prefix = 'adapter-library/';
        if (!str_starts_with($destination, $prefix)) {
            throw new RuntimeException("Projection destination is outside adapter-library/: $destination");
        }
        $relative = substr($destination, strlen($prefix));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
            throw new RuntimeException("Projection destination is not a canonical relative path: $destination");
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("Projection destination escapes adapter-library/: $destination");
            }
        }

        return $relative;
    }

    private static function readStableSource(string $source): string
    {
        $first = self::readSourceOnce($source);
        $second = self::readSourceOnce($source);
        if (!hash_equals(hash('sha256', $first), hash('sha256', $second)) || strlen($first) !== strlen($second)) {
            throw new RuntimeException("Adapter package source changed during assembly: $source");
        }

        return $second;
    }

    private static function readSourceOnce(string $source): string
    {
        $pathStat = @lstat($source);
        if ($pathStat === false || ($pathStat['mode'] & self::TYPE_MODE) !== self::FILE_MODE || is_link($source)) {
            throw new RuntimeException("Adapter package source is not an ordinary regular file: $source");
        }
        if (realpath($source) !== $source) {
            throw new RuntimeException("Adapter package source path changed or escaped during assembly: $source");
        }

        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open adapter package source: $source");
        }
        try {
            $openedStat = fstat($handle);
            if ($openedStat === false
                || ($openedStat['mode'] & self::TYPE_MODE) !== self::FILE_MODE
                || $openedStat['dev'] !== $pathStat['dev']
                || $openedStat['ino'] !== $pathStat['ino']) {
                throw new RuntimeException("Adapter package source changed while it was opened: $source");
            }
            $bytes = stream_get_contents($handle);
            if ($bytes === false) {
                throw new RuntimeException("Cannot read adapter package source: $source");
            }
            $afterStat = fstat($handle);
            if ($afterStat === false
                || $afterStat['dev'] !== $openedStat['dev']
                || $afterStat['ino'] !== $openedStat['ino']
                || $afterStat['size'] !== $openedStat['size']
                || strlen($bytes) !== $afterStat['size']) {
                throw new RuntimeException("Adapter package source changed while it was read: $source");
            }
        } finally {
            fclose($handle);
        }

        $finalStat = @lstat($source);
        if ($finalStat === false
            || ($finalStat['mode'] & self::TYPE_MODE) !== self::FILE_MODE
            || $finalStat['dev'] !== $pathStat['dev']
            || $finalStat['ino'] !== $pathStat['ino']
            || $finalStat['size'] !== $pathStat['size']) {
            throw new RuntimeException("Adapter package source changed after it was read: $source");
        }

        return $bytes;
    }

    private static function writeExclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Cannot create projected adapter-library member: $path");
        }
        $writeFailure = null;
        try {
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Cannot write projected adapter-library member: $path");
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException("Cannot flush projected adapter-library member: $path");
            }
        } catch (Throwable $throwable) {
            $writeFailure = $throwable;
        } finally {
            fclose($handle);
        }
        if ($writeFailure !== null) {
            if (!@unlink($path)) {
                throw new RuntimeException(
                    "Cannot remove incomplete projected adapter-library member: $path",
                    0,
                    $writeFailure
                );
            }
            throw $writeFailure;
        }
        if (!chmod($path, self::OUTPUT_FILE_PERMISSIONS) || !touch($path, self::OUTPUT_MTIME)) {
            if (!@unlink($path)) {
                throw new RuntimeException("Cannot remove unnormalized projected adapter-library member: $path");
            }
            throw new RuntimeException("Cannot normalize projected adapter-library member: $path");
        }
    }

    private static function makeParents(string $root, string $relative): void
    {
        if ($relative === '.' || $relative === '') {
            return;
        }
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            if (!self::nodeExists($current)) {
                if (!mkdir($current, self::OUTPUT_DIRECTORY_PERMISSIONS)) {
                    throw new RuntimeException("Cannot create projected adapter-library directory: $current");
                }
                continue;
            }
            $stat = @lstat($current);
            if ($stat === false || ($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE || is_link($current)) {
                throw new RuntimeException("Projected adapter-library parent is not an ordinary directory: $current");
            }
        }
    }

    /** @param list<array{path:string,sha256:string,size:int}> $rows */
    private static function verifyTree(string $build, array $rows): void
    {
        $expectedFiles = [];
        $expectedDirectories = ['' => true];
        foreach ($rows as $row) {
            $relative = self::libraryRelativePath($row['path']);
            $expectedFiles[$relative] = $row;
            $parent = dirname($relative);
            while ($parent !== '.' && $parent !== '') {
                $expectedDirectories[$parent] = true;
                $parent = dirname($parent);
            }
        }
        ksort($expectedFiles, SORT_STRING);
        ksort($expectedDirectories, SORT_STRING);

        $actualFiles = [];
        $actualDirectories = ['' => true];
        self::inventory($build, '', $actualFiles, $actualDirectories);
        ksort($actualFiles, SORT_STRING);
        ksort($actualDirectories, SORT_STRING);
        if (array_keys($actualFiles) !== array_keys($expectedFiles)
            || array_keys($actualDirectories) !== array_keys($expectedDirectories)) {
            throw new RuntimeException('Projected adapter-library tree contains a missing or non-allowlisted member');
        }
        foreach ($expectedFiles as $relative => $row) {
            $actual = $actualFiles[$relative];
            if ($actual['size'] !== $row['size'] || !hash_equals($actual['sha256'], $row['sha256'])) {
                throw new RuntimeException("Projected adapter-library member changed after copy: $relative");
            }
        }
    }

    /**
     * @param array<string,array{sha256:string,size:int}> $files
     * @param array<string,true> $directories
     */
    private static function inventory(string $root, string $relative, array &$files, array &$directories): void
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Cannot inspect projected adapter-library directory: $directory");
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
            $path = $root . '/' . $childRelative;
            $stat = @lstat($path);
            if ($stat === false || is_link($path)) {
                throw new RuntimeException("Projected adapter-library member is missing or symlinked: $childRelative");
            }
            $type = $stat['mode'] & self::TYPE_MODE;
            if ($type === self::DIRECTORY_MODE) {
                $directories[$childRelative] = true;
                self::inventory($root, $childRelative, $files, $directories);
                continue;
            }
            if ($type !== self::FILE_MODE) {
                throw new RuntimeException("Projected adapter-library member is not an ordinary file: $childRelative");
            }
            $digest = hash_file('sha256', $path);
            if ($digest === false) {
                throw new RuntimeException("Cannot hash projected adapter-library member: $childRelative");
            }
            $files[$childRelative] = ['sha256' => $digest, 'size' => $stat['size']];
        }
    }

    private static function normalizeDirectories(string $root): void
    {
        $directories = [$root];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $directories[] = $item->getPathname();
            }
        }
        foreach ($directories as $directory) {
            if (!chmod($directory, self::OUTPUT_DIRECTORY_PERMISSIONS)
                || !touch($directory, self::OUTPUT_MTIME)) {
                throw new RuntimeException("Cannot normalize projected adapter-library directory: $directory");
            }
        }
    }

    /**
     * Publish marker and target as one rollbackable staging transaction.
     *
     * Cleanup of a successfully published backup is deliberately post-commit:
     * deleting it can fail after deleting only some members, at which point it
     * is no longer an exact rollback source. Such cleanup can retain scratch,
     * but it cannot turn a complete new marker/library pair into a thrown,
     * half-committed result.
     *
     * @param null|callable(string):void $checkpoint
     */
    private static function publish(
        string $build,
        string $target,
        string $backup,
        string $agent,
        ?callable $checkpoint
    ): void
    {
        $markerCreated = false;
        $hadTarget = false;
        $oldMoved = false;
        $newPublished = false;
        try {
            $markerCreated = self::writeDeploymentMarker($agent);
            self::checkpoint($checkpoint, 'marker-written');

            // Recheck at the mutation boundary. Assembly can take long enough
            // for a staging caller to replace the target after the initial
            // inventory, and a newly appeared unsafe node is never ours to move.
            self::assertReplaceableTarget($target, $agent);
            $hadTarget = self::nodeExists($target);
            if ($hadTarget) {
                if (!@rename($target, $backup)) {
                    throw new RuntimeException("Cannot move prior staged adapter-library to rollback backup: $target");
                }
                $oldMoved = true;
            }
            if (!@rename($build, $target)) {
                throw new RuntimeException("Cannot atomically publish staged adapter-library: $target");
            }
            $newPublished = true;
            self::checkpoint($checkpoint, 'target-published');
            self::checkpoint($checkpoint, 'pre-backup-cleanup');
        } catch (Throwable $throwable) {
            try {
                self::rollbackPublication(
                    $build,
                    $target,
                    $backup,
                    $agent,
                    $markerCreated,
                    $oldMoved,
                    $newPublished
                );
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    $throwable->getMessage() . '; publication rollback failed: ' . $rollbackFailure->getMessage(),
                    0,
                    $throwable
                );
            }
            throw $throwable;
        }

        if ($hadTarget) {
            try {
                self::checkpoint($checkpoint, 'backup-cleanup');
                self::removeOwnedTree($backup, dirname(dirname($target)));
            } catch (Throwable) {
                // Publication is committed and the new marker/library pair is
                // complete. A partially removed backup cannot safely be
                // restored, so retain it for caller cleanup without converting
                // a successful publication into a misleading failure.
            }
        }
    }

    private static function rollbackPublication(
        string $build,
        string $target,
        string $backup,
        string $agent,
        bool $markerCreated,
        bool $oldMoved,
        bool $newPublished
    ): void {
        if ($newPublished) {
            if (self::nodeExists($build) || !@rename($target, $build)) {
                throw new RuntimeException("Cannot withdraw failed staged adapter-library publication: $target");
            }
        }
        if ($oldMoved && !@rename($backup, $target)) {
            throw new RuntimeException("Cannot restore prior staged adapter-library after publication failure: $target");
        }
        if (!$markerCreated) {
            return;
        }
        $marker = $agent . '/' . self::DEPLOYMENT_MARKER;
        if (!self::nodeExists($marker)
            || is_link($marker)
            || !is_file($marker)
            || file_get_contents($marker) !== self::DEPLOYMENT_MARKER_BYTES
            || !@unlink($marker)) {
            throw new RuntimeException("Cannot restore prior adapter-library deployment marker state: $marker");
        }
    }

    /** @param null|callable(string):void $checkpoint */
    private static function checkpoint(?callable $checkpoint, string $phase): void
    {
        if ($checkpoint !== null) {
            $checkpoint($phase);
        }
    }

    private static function assertReplaceableTarget(string $target, string $agent): void
    {
        if (!self::nodeExists($target)) {
            return;
        }
        $stat = @lstat($target);
        if ($stat === false || ($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE || is_link($target)) {
            throw new RuntimeException("Staged adapter-library target is not an ordinary directory: $target");
        }
        $files = [];
        $directories = ['' => true];
        self::inventory($target, '', $files, $directories);
        if (!str_starts_with($target, $agent . '/')) {
            throw new RuntimeException("Staged adapter-library target escapes its agent root: $target");
        }
    }

    private static function ordinaryDirectory(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link(rtrim($path, '/'))) {
            throw new RuntimeException("$label is not an ordinary directory: $path");
        }
        $resolved = realpath($path);
        $stat = $resolved === false ? false : @lstat($resolved);
        if ($resolved === false
            || $stat === false
            || ($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE
            || !is_readable($resolved)) {
            throw new RuntimeException("$label is not an ordinary readable directory: $path");
        }

        return rtrim($resolved, '/');
    }

    /** @param list<array{path:string,sha256:string,size:int}> $rows */
    private static function libraryDigest(array $rows): string
    {
        $context = hash_init('sha256');
        foreach ($rows as $row) {
            hash_update($context, $row['path'] . "\0" . $row['size'] . "\0" . $row['sha256'] . "\n");
        }

        return hash_final($context);
    }

    private static function removeOwnedTree(string $path, string $parent): void
    {
        if (!str_starts_with($path, rtrim($parent, '/') . '/')) {
            throw new RuntimeException("Refusing to remove assembly path outside its scratch parent: $path");
        }
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE || is_link($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException("Cannot remove adapter-library assembly node: $path");
            }
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException("Cannot enumerate adapter-library assembly directory for cleanup: $path");
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            self::removeOwnedTree($path . '/' . $entry, $parent);
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("Cannot remove adapter-library assembly directory: $path");
        }
    }

    private static function nodeExists(string $path): bool
    {
        return @lstat($path) !== false;
    }
}
