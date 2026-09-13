<?php
declare(strict_types=1);

final class MapConcurrencyEvidence {
    public static function refusal(string $case): array {
        [$command, $code, $message, $remediation] = match ($case) {
            'same' => ['capture', 'capture_lock_held',
                'capture refused because another publisher holds the destination lock',
                'wait for the current publisher to finish, verify its receipt, then start a new capture'],
            'other' => ['capture', 'capture_target_writer_active',
                'capture refused because another WPrism target writer or promotion session is active',
                'wait for the active writer to finish; if none is running, inspect and recover or abort the retained promotion session before retrying capture'],
            'apply' => ['apply', 'process_fence_held',
                'another live process on this target holds the promotion fence; concurrent target mutation was refused',
                'wait for the capture, apply, or promotion already running on this target to finish or release its fence, then retry this command'],
            default => throw new RuntimeException('unknown concurrency case'),
        };
        $answer = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => $command,
            'error' => $code, 'reason_code' => $code, 'message' => $message, 'remediation' => $remediation];
        if ($case === 'same') $answer['diagnostics'] = [['code' => $code,
            'message' => 'another publisher owns the capture destination',
            'remediation' => 'wait for the publisher and verify its capture receipt']];
        return $answer;
    }

    public static function assertPhase(array $answer, string $expected): void {
        if (!in_array($expected, ['', 'locked', 'release'], true)
            || $answer !== ['format' => 'wprism-map-concurrency-phase/v1', 'phase' => $expected]) {
            throw new RuntimeException('native concurrency phase differs');
        }
    }

    public static function assertCapture(array $answer, string $destination): void {
        if (!in_array($destination, ['holder', 'other'], true)
            || array_keys($answer) !== ['counts', 'media', 'notes', 'warnings', 'state_dir', 'revision_hash', 'initial_code_baseline', 'initial_publication_cleanup']
            || !is_array($answer['counts']) || array_keys($answer['counts']) !== ['post', 'term', 'menu', 'sidebar', 'options', 'deletion']
            || !is_int($answer['media']) || $answer['media'] < 0
            || !is_array($answer['notes']) || !array_is_list($answer['notes'])
            || $answer['warnings'] !== [] || $answer['state_dir'] !== '/siterepo/.tmp-map-concurrency/' . $destination
            || $answer['revision_hash'] !== null || $answer['initial_code_baseline'] !== null
            || $answer['initial_publication_cleanup'] !== 'not-applicable') throw new RuntimeException('concurrent capture did not publish cleanly');
        foreach ($answer['counts'] as $count) {
            if (!is_int($count) || $count < 0) throw new RuntimeException('capture count is malformed');
        }
        if ($answer['counts']['post'] < 2 || $answer['counts']['options'] < 1 || $answer['counts']['deletion'] !== 0) {
            throw new RuntimeException('captured fixture inventory missing');
        }
    }

    public static function assertTransport(string $case, string $pair, string $stem): void {
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1) throw new RuntimeException('concurrency transport pair malformed');
        $success = in_array($case, ['holder', 'retry'], true);
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $bytes = \WPrismTest\PrivateCommandOutput::readObject($stem, $prelude, expectedExit: $success ? 0 : 1);
        $answer = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if ($success) self::assertCapture($answer, $case === 'holder' ? 'holder' : 'other');
        elseif ($answer !== self::refusal($case)) throw new RuntimeException('concurrency refusal differs');
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'transport' && count($argv) === 5) {
            MapConcurrencyEvidence::assertTransport($argv[2], $argv[3], $argv[4]);
        } elseif (($argv[1] ?? '') === 'phase' && count($argv) === 3) {
            MapConcurrencyEvidence::assertPhase(json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR), $argv[2]);
        } else throw new RuntimeException('unknown concurrency evidence operation');
    } catch (Throwable $failure) {
        fwrite(STDERR, "Map Block concurrency evidence refused\n");
        exit(1);
    }
}
