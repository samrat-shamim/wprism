<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/StoragePrerequisiteGrammar.php';
require_once __DIR__ . '/ExactOptionReader.php';
require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/LockedOptionRows.php';
require_once __DIR__ . '/TransactionalTableBoundary.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/PlainData.php';

/** Native migration cursors are target prerequisites, never portable authored state. */
final class StoragePrerequisites {
    public static function assert_ready(array $manifests): void {
        foreach (StoragePrerequisiteGrammar::project($manifests) as $row) {
            // A warm object cache or option filter is not a durable migration
            // witness. Reuse the exact reader's bounded allocation and race
            // checks; SQL failures remain failures, never missing cursors.
            $observed = ExactOptionReader::read_row(
                $row['option'], 'storage prerequisite', maxValueBytes: 1024
            );
            self::assert_value($row, $observed['value'] ?? null);
        }
    }

    /** Keep the exact prerequisite coordinates stable until the caller commits. */
    public static function lock(array $manifests): void {
        $rows = StoragePrerequisiteGrammar::project($manifests);
        if ($rows === []) return;
        usort($rows, static fn(array $a, array $b): int => strcmp($a['option'], $b['option']));
        global $wpdb;
        $authority = Db::transaction_authority('storage prerequisite lock');
        $index = TransactionalTableBoundary::full_width_unique_lock_index(
            $wpdb->options, 'option_name', $authority, 'storage prerequisite'
        );
        foreach ($rows as $row) {
            $observed = LockedOptionRows::read_optional(
                $row['option'], $index, $authority, 'storage prerequisite', maxValueBytes: 1024
            );
            self::assert_value($row, $observed === null ? null
                : PlainData::decode($observed['option_value'], 'storage prerequisite'));
        }
    }

    private static function assert_value(array $row, mixed $value): void {
        if ($value === $row['equals']) return;
        $message = 'native storage does not satisfy the pinned adapter prerequisite';
        $remediation = 'complete the plugin native migration under its supported maintenance procedure, then retry';
        throw new CommandRefusalException(
            'storage_prerequisite_unmet', $message, $remediation,
            [[
                'code' => 'storage_prerequisite_unmet',
                'manifest' => $row['manifest'],
                'option' => $row['option'],
                'message' => $message,
                'remediation' => $remediation,
            ]],
            'wprism: storage prerequisite unmet for adapter ' . $row['manifest']
                . '; ' . $remediation . '; no authored work authorized'
        );
    }
}
