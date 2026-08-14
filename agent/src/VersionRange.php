<?php
declare(strict_types=1);

namespace Duo;

/** Pure min-inclusive/max-exclusive version window. */
final class VersionRange {
    public static function contains(string $installed, string $min, string $max): bool {
        return version_compare($installed, $min, '>=')
            && version_compare($installed, $max, '<');
    }
}
