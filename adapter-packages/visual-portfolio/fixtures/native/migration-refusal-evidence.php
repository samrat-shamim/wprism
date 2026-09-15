<?php
declare(strict_types=1);

/** Private live oracle for the exact child-side migration refusal. */
final class VisualPortfolioMigrationRefusalEvidence
{
    public const PUBLIC_MESSAGE = "wprism: provider 'visual-portfolio-migrations' capability 'settle_storage' failed";
    private const PRIVATE_MESSAGE = 'wprism: Visual Portfolio legacy archive-slug migration requires native maintenance with irreversible rewrite effects';
    private const CHILD_FAILURE_FORMAT = 'wprism-provider-operation-failure/v1';

    public static function matches(Throwable $failure): bool
    {
        if (!$failure instanceof WPrism\PrivateEvidenceException
            || $failure->getMessage() !== self::PUBLIC_MESSAGE) {
            return false;
        }

        $pending = [$failure];
        $visited = [];
        $documents = 0;
        $matches = 0;
        while ($pending !== [] && count($visited) < 32) {
            $node = array_shift($pending);
            $id = spl_object_id($node);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $document = self::document($node->getMessage());
            if ($document !== null) {
                $documents++;
                if (self::matchesChildGraph($document)) {
                    $matches++;
                }
            }
            if ($node instanceof WPrism\PrivateEvidenceCarrierException) {
                foreach ($node->private_evidence_causes() as $cause) {
                    $pending[] = $cause;
                }
            }
            if ($node->getPrevious() !== null) {
                $pending[] = $node->getPrevious();
            }
        }
        return $pending === [] && $documents === 1 && $matches === 1;
    }

    private static function document(string $message): ?array
    {
        try {
            $value = json_decode($message, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        return is_array($value) && ($value['format'] ?? null) === self::CHILD_FAILURE_FORMAT
            ? $value
            : null;
    }

    private static function matchesChildGraph(array $document): bool
    {
        $request = $document['request_sha256'] ?? null;
        $evidence = $document['evidence'] ?? null;
        if (!is_string($request) || preg_match('/^[a-f0-9]{64}$/D', $request) !== 1
            || !is_array($evidence)
            || !is_array($evidence['throwable'] ?? null)
            || !array_is_list($evidence['throwable'])
            || count($evidence['throwable']) !== 1
            || ($evidence['traversal']['scan_complete'] ?? null) !== true
            || ($evidence['traversal']['record_complete'] ?? null) !== true) {
            return false;
        }
        $cause = $evidence['throwable'][0];
        return is_array($cause)
            && ($cause['index'] ?? null) === 0
            && array_key_exists('parent_index', $cause)
            && $cause['parent_index'] === null
            && ($cause['relation'] ?? null) === 'root'
            && ($cause['class'] ?? null) === RuntimeException::class
            && ($cause['message'] ?? null) === self::PRIVATE_MESSAGE
            && ($cause['message_encoding'] ?? null) === 'utf-8'
            && ($cause['message_truncated'] ?? null) === false;
    }
}
