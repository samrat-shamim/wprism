<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/EvidenceSizeProfile.php';

/** Admit one retained command's complete object output without interpreting its meaning. */
final class PrivateCommandOutput {
    public static function readObject(string $stem, ?string $stderrPattern = null, string $profile = EvidenceSizeProfile::COMPACT): string {
        $limits = EvidenceSizeProfile::limits($profile);
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
        if ($streams['exit'] !== "0\n") {
            throw new \RuntimeException('private command output did not succeed');
        }
        foreach (explode("\n", $streams['stderr']) as $line) {
            if ($line !== '' && ($stderrPattern === null || preg_match($stderrPattern, $line) !== 1)) {
                throw new \RuntimeException('private command output has an unexpected diagnostic');
            }
        }
        $record = json_decode($streams['stdout'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_is_list($record)) {
            throw new \RuntimeException('private command output is not one nonempty object');
        }
        return $streams['stdout'];
    }
}
