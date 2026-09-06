<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/EvidenceSizeProfile.php';

/** Transport admission is shared; each owner still proves its output's meaning. */
final class PrivateCommandOutput {
    public static function readObject(string $stem, ?string $stderrPattern = null, string $profile = EvidenceSizeProfile::COMPACT, int $expectedExit = 0): string {
        $bytes = self::readBytes($stem, $stderrPattern, $profile, $expectedExit);
        $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_is_list($record)) {
            throw new \RuntimeException('private command output is not one nonempty object');
        }
        return $bytes;
    }

    /** Native dumps are opaque bytes, not JSON. Empty output is not evidence of an empty database. */
    public static function readBytes(string $stem, ?string $stderrPattern = null, string $profile = EvidenceSizeProfile::COMPACT, int $expectedExit = 0): string {
        $limits = EvidenceSizeProfile::limits($profile);
        if ($expectedExit < 0 || $expectedExit > 255) {
            throw new \RuntimeException('private command output requires an exact process exit status');
        }
        if (!str_starts_with($stem, '/')) {
            throw new \RuntimeException('private command output requires an absolute stem');
        }
        $directory = @lstat(dirname($stem));
        if (!is_array($directory) || ($directory['mode'] & 0177777) !== 0040700) {
            throw new \RuntimeException('private command output directory is not private');
        }
        $streams = [];
        foreach (['stdout' => $limits['stdout_bytes'], 'stderr' => 1048576, 'exit' => 8] as $suffix => $limit) {
            $path = "$stem.$suffix";
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1
                || $stat['uid'] !== $directory['uid'] || $stat['size'] > $limit) {
                throw new \RuntimeException('private command output stream is unsafe or oversized');
            }
            $bytes = @file_get_contents($path, false, null, 0, $limit + 1);
            if (!is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new \RuntimeException('private command output stream changed while reading');
            }
            $streams[$suffix] = $bytes;
        }
        if ($streams['exit'] !== $expectedExit . "\n") {
            throw new \RuntimeException($expectedExit === 0
                ? 'private command output did not succeed'
                : 'private command output did not return its expected exit status');
        }
        foreach (explode("\n", $streams['stderr']) as $line) {
            if ($line !== '' && ($stderrPattern === null || preg_match($stderrPattern, $line) !== 1)) {
                throw new \RuntimeException('private command output has an unexpected diagnostic');
            }
        }
        return $streams['stdout'];
    }
}
