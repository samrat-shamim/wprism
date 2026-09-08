<?php
declare(strict_types=1);

namespace WPrismTest;

/** Caller-selected test transport budgets; a retained record cannot enlarge its own authority. */
final class EvidenceSizeProfile {
    public const COMPACT = 'compact/v1';
    public const CONFORMANCE_TREE = 'conformance-tree/v1';
    public const NATIVE_DATABASE = 'native-database/v1';

    /** @return array{tree_bytes:int,tree_record_bytes:int,stdout_bytes:int} */
    public static function limits(string $profile): array {
        return match ($profile) {
            self::COMPACT => ['tree_bytes' => 262144, 'tree_record_bytes' => 491520, 'stdout_bytes' => 1048576],
            // Three existing Polylang Unicode bodies total 310800 bytes before
            // canonical overhead. One full fixture tree fits this explicit
            // profile; callers retain before/after as separate private records.
            self::CONFORMANCE_TREE => ['tree_bytes' => 1048576, 'tree_record_bytes' => 1835008, 'stdout_bytes' => 2097152],
            // Native admin cache population produced a 5,574,808-byte complete
            // dump. Owners opt into whole-database transport, never table
            // exclusions; filesystem content/record budgets stay unchanged.
            self::NATIVE_DATABASE => ['tree_bytes' => 1048576, 'tree_record_bytes' => 1835008, 'stdout_bytes' => 16777216],
            default => throw new \RuntimeException('private evidence size profile is unsupported'),
        };
    }
}
