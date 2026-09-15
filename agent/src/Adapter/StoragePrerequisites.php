<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/StoragePrerequisiteGrammar.php';
require_once __DIR__ . '/../Kernel/ExactOptionReader.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Policy/Policy.php';

/** Native migration cursors are target prerequisites, never portable authored state. */
final class StoragePrerequisites {
    public static function assert_ready(Policy $policy): void {
        foreach (StoragePrerequisiteGrammar::project($policy->manifests) as $row) {
            // A warm object cache or option filter is not a durable migration
            // witness. Reuse the exact reader's bounded allocation and race
            // checks; SQL failures remain failures, never missing cursors.
            $observed = ExactOptionReader::read_row(
                $row['option'], 'storage prerequisite', maxValueBytes: 1024
            );
            if ($observed !== null && $observed['value'] === $row['equals']) continue;
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
}
