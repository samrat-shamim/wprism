<?php
declare(strict_types=1);

/** Exact core fixture causes; generic private-store/graph machinery stays test-shared. */
const WPRISM_CORE_DELETION_EXCLUSION_CAUSE =
    'wprism: deletion refused before mutation — no exact held external writer exclusion is bound';

/** @return array<string,mixed> */
function core_private_refusal_profile(string $name, string $contextJson): array {
    if (strlen($contextJson) > 65536) {
        throw new RuntimeException('core private refusal context exceeds its byte boundary');
    }
    try {
        $context = json_decode($contextJson, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('core private refusal context is not bounded JSON');
    }
    $leaf = [
        'parent_index' => null,
        'relation' => 'root',
        'class' => 'WPrism\CommandRefusalException',
        'message' => WPRISM_CORE_DELETION_EXCLUSION_CAUSE,
    ];
    $warnings = [];
    $reason = 'deletion_writer_exclusion_required';
    if ($name === 'plain' && $contextJson === '{}') {
        return ['command' => 'apply', 'reason_code' => $reason, 'nodes' => [$leaf]];
    }
    if (!is_array($context)) {
        throw new RuntimeException('core private refusal context is not one object');
    }
    if ($name === 'forced-comments') {
        $keys = array_keys($context);
        sort($keys, SORT_STRING);
        if ($keys !== ['comment_id', 'uuid']
            || !core_private_refusal_uuid($context['uuid'])
            || !is_int($context['comment_id']) || $context['comment_id'] < 1) {
            throw new RuntimeException('core forced-comment context does not identify one exact fixture guard');
        }
        $warnings[] = "FORCED delete of guarded page {$context['uuid']}: 1 rows in comments will be orphaned; "
            . 'comments is not a declared authored-snapshot table and must be resolved through its owning content workflow. '
            . "Surviving rows: comments.comment_ID={$context['comment_id']}";
        $rootClass = 'RuntimeException';
        $reason = 'apply_failed';
    } elseif ($name === 'forced-conflicts') {
        if (array_keys($context) !== ['delete_conflict']
            || !is_array($context['delete_conflict']) || !array_is_list($context['delete_conflict'])
            || count($context['delete_conflict']) < 1 || count($context['delete_conflict']) > 16) {
            throw new RuntimeException('core forced-conflict context is not one bounded frozen plan roster');
        }
        $seen = [];
        foreach ($context['delete_conflict'] as $row) {
            if (!is_array($row) || array_keys($row) !== ['uuid', 'reason']
                || !core_private_refusal_uuid($row['uuid']) || isset($seen[$row['uuid']])
                || !in_array($row['reason'], [
                    'target entity changed locally since the tombstone base',
                    'tombstone expected hash does not match the target last-synced base',
                ], true)) {
                throw new RuntimeException('core forced-conflict context contains an unrelated fixture row');
            }
            $seen[$row['uuid']] = true;
            $warnings[] = "FORCED deletion conflict {$row['uuid']} ({$row['reason']})";
        }
        $rootClass = 'WPrism\CommandRefusalException';
        $reason = 'apply_forced_override_failed';
    } else {
        throw new RuntimeException('unknown core private refusal profile');
    }
    $root = [
        'parent_index' => null,
        'relation' => 'root',
        'class' => $rootClass,
        'message' => implode("\n", array_map(static fn(string $warning): string => 'Warning: ' . $warning, $warnings))
            . "\n" . WPRISM_CORE_DELETION_EXCLUSION_CAUSE,
    ];
    $leaf['parent_index'] = 0;
    $leaf['relation'] = 'previous';
    return ['command' => 'apply', 'reason_code' => $reason, 'nodes' => [$root, $leaf]];
}

function core_private_refusal_uuid(mixed $uuid): bool {
    return is_string($uuid)
        && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $uuid) === 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}
try {
    $library = $argv[1] ?? '';
    $mode = $argv[2] ?? '';
    if (!in_array($mode, ['snapshot', 'verify'], true) || count($argv) !== ($mode === 'snapshot' ? 6 : 7)) {
        throw new RuntimeException('core private refusal expects explicit test-library, mode, profile, directory and frozen context');
    }
    $profile = core_private_refusal_profile($argv[3], $argv[5]);
    if ($library === '' || !is_file($library) || !is_readable($library)) {
        throw new RuntimeException('the explicitly mounted shared test library is unavailable');
    }
    require_once $library;
    echo $mode === 'snapshot'
        ? WPrismTest\PrivateRefusalReceipt::snapshot($argv[4], $profile)
        : WPrismTest\PrivateRefusalReceipt::verify($argv[4], $argv[6], $profile);
    echo "\n";
} catch (RuntimeException $failure) {
    fwrite(STDERR, 'wprism-core-private-refusal: ' . $failure->getMessage() . "\n");
    exit(1);
}
