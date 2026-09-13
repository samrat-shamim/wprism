<?php
declare(strict_types=1);

// Native wp eval-file already loads Canon; standalone host evidence uses the
// same wire encoder, but independently checks every record relationship.
if (!class_exists(\WPrism\Canon::class)) require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

final class MapRecoveryEvidence {
    public const CHECKPOINTS = ['intent-written', 'intent-ready', 'after-commit-marker', 'receipt-written'];

    private static function digest(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function record(array $record, string $kind): void {
        $expected = match ($kind) {
            'intent' => ['candidate_sha256', 'created_at', 'format', 'id', 'phase', 'previous_sha256', 'record_sha256'],
            'receipt' => ['candidate_sha256', 'committed_at', 'format', 'intent_id', 'phase', 'previous_sha256', 'record_sha256'],
            'commit-marker' => ['candidate_sha256', 'format', 'intent_id', 'previous_sha256', 'record_sha256', 'state_sha256'],
            default => throw new RuntimeException('unknown recovery record kind'),
        };
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $seal = $record['record_sha256'] ?? null;
        if ($keys !== $expected || ($record['format'] ?? null) !== 'wprism-capture-' . $kind . '/v1'
            || !self::digest($seal) || !self::digest($record['candidate_sha256'] ?? null) || !self::digest($record['previous_sha256'] ?? null)
            || !is_string($record[$kind === 'intent' ? 'id' : 'intent_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record[$kind === 'intent' ? 'id' : 'intent_id']) !== 1) {
            throw new RuntimeException('recovery record shape differs');
        }
        unset($record['record_sha256']);
        if (!hash_equals($seal, hash('sha256', \WPrism\Canon::encode($record)))) throw new RuntimeException('recovery record self-hash differs');
        if ($kind === 'commit-marker') {
            if (!self::digest($record['state_sha256'])) throw new RuntimeException('recovery marker destination differs');
        } else {
            $time = $record[$kind === 'intent' ? 'created_at' : 'committed_at'];
            if (!is_string($time) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $time) !== 1
                || ($kind === 'intent' ? !in_array($record['phase'], ['prepared', 'swapped', 'ready', 'committing'], true) : $record['phase'] !== 'committed')) {
                throw new RuntimeException('recovery record phase or timestamp differs');
            }
        }
    }

    public static function assertCrash(array $answer, string $checkpoint): void {
        if (!in_array($checkpoint, self::CHECKPOINTS, true)
            || array_keys($answer) !== ['format', 'checkpoint', 'prior_sha256', 'candidate_sha256', 'live_sha256', 'staging_sha256', 'backup_sha256', 'intent', 'receipt', 'marker', 'destination_sha256', 'transitions']
            || $answer['format'] !== 'wprism-map-recovery-observation/v1' || $answer['checkpoint'] !== $checkpoint
            || !self::digest($answer['prior_sha256']) || !self::digest($answer['candidate_sha256'])
            || $answer['prior_sha256'] === $answer['candidate_sha256'] || !self::digest($answer['destination_sha256'])
            || $answer['transitions'] !== [] || !is_array($answer['intent'])) throw new RuntimeException('recovery observation premise differs');
        $intent = $answer['intent'];
        self::record($intent, 'intent');
        $phase = match ($checkpoint) { 'intent-written' => 'prepared', 'intent-ready' => 'ready', default => 'committing' };
        $beforeSwap = $checkpoint === 'intent-written';
        if ($intent['phase'] !== $phase || $intent['previous_sha256'] !== $answer['prior_sha256'] || $intent['candidate_sha256'] !== $answer['candidate_sha256']
            || $answer['live_sha256'] !== $answer[$beforeSwap ? 'prior_sha256' : 'candidate_sha256']
            || $answer['staging_sha256'] !== ($beforeSwap ? $answer['candidate_sha256'] : null)
            || $answer['backup_sha256'] !== ($beforeSwap ? null : $answer['prior_sha256'])) throw new RuntimeException('recovery publication placement differs');
        $receipt = $answer['receipt'];
        if ($receipt !== null) {
            if (!is_array($receipt)) throw new RuntimeException('recovery receipt missing');
            self::record($receipt, 'receipt');
        }
        if ($checkpoint === 'receipt-written') {
            if (!is_array($receipt) || !is_array($answer['marker'])) throw new RuntimeException('committed recovery evidence missing');
            self::record($answer['marker'], 'commit-marker');
            foreach ([$receipt, $answer['marker']] as $record) {
                if ($record['intent_id'] !== $intent['id'] || $record['candidate_sha256'] !== $intent['candidate_sha256']
                    || $record['previous_sha256'] !== $intent['previous_sha256']) throw new RuntimeException('committed recovery records disagree');
            }
            if ($answer['marker']['state_sha256'] !== $answer['destination_sha256']) throw new RuntimeException('commit proof belongs to another destination');
        } elseif ($answer['marker'] !== null || ($receipt !== null && $receipt['intent_id'] === $intent['id'])) {
            throw new RuntimeException('uncommitted recovery has contradictory commit evidence');
        }
    }

    public static function warnings(string $checkpoint, string $state = '/siterepo/state'): array {
        return match ($checkpoint) {
            'intent-written' => ["recovered: removed pre-swap staging dir $state.capture-staging and intent (candidate was never published)"],
            'intent-ready' => ["RECOVERED: restored $state from the retained backup before COMMIT was attempted"],
            'after-commit-marker' => ['RECOVERED: database commit marker was absent/prior; rolled back the uncommitted publication'],
            'receipt-written' => ["recovered: removed retained post-commit backup $state.capture-backup", 'recovered: finalized the durable capture intent after its commit receipt was found'],
            default => throw new RuntimeException('unknown recovery checkpoint'),
        };
    }

    public static function publicRefusal(): array {
        return ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'capture',
            'error' => 'capture_recovery_ambiguous', 'reason_code' => 'capture_recovery_ambiguous',
            'message' => 'capture recovery found an ambiguous publication or commit boundary',
            'remediation' => 'do not retry or discard the retained backup; inspect the durable intent, receipt, and database commit proof before continuing',
            'diagnostics' => [['code' => 'capture_recovery_ambiguous', 'message' => 'durable recovery evidence does not authorize an automatic choice',
                'remediation' => 'preserve the intent, receipt, and retained backup and reconcile the recorded publication manually']]];
    }

    public static function refusalProfile(string $kind): array {
        $message = match ($kind) {
            'intent', 'receipt' => 'wprism: capture recovery is blocked by an ambiguous publication/COMMIT boundary — capture recovery found a tampered ' . $kind . ' record; refusing to retry or discard the retained backup. Inspect the intent, receipt, and database before continuing.',
            'marker' => 'wprism: capture recovery found a current-intent commit marker with mismatched digests; refusing to retry or discard retained evidence',
            default => throw new RuntimeException('unknown recovery refusal kind'),
        };
        return ['command' => 'capture', 'reason_code' => 'capture_recovery_ambiguous',
            'nodes' => [['parent_index' => null, 'relation' => 'root', 'class' => 'WPrism\\CommandRefusalException', 'message' => $message]]];
    }

    public static function assertPrivate(string $kind, string $pair, string $stem): void {
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
        $prelude = self::prelude($pair);
        if (basename($stem) !== 'private') throw new RuntimeException('private recovery transport binding differs');
        $read = static fn(string $path, int $exit): array => json_decode(\WPrismTest\PrivateCommandOutput::readObject(
            $path, $prelude, \WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE, $exit
        ), true, 32, JSON_THROW_ON_ERROR);
        if ($read(dirname($stem) . '/command', 1) !== self::publicRefusal()) throw new RuntimeException('native recovery public refusal differs');
        \WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($read($stem, 0), self::refusalProfile($kind));
    }

    private static function prelude(string $pair): string {
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1) throw new RuntimeException('recovery pair binding malformed');
        return '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
    }

    public static function assertCapture(array $answer, string $checkpoint): void {
        $warnings = $checkpoint === 'clean' ? [] : self::warnings($checkpoint);
        if (array_keys($answer) !== ['counts', 'media', 'notes', 'warnings', 'state_dir', 'revision_hash', 'initial_code_baseline', 'initial_publication_cleanup']
            || !is_array($answer['counts']) || array_keys($answer['counts']) !== ['post', 'term', 'menu', 'sidebar', 'options', 'deletion']
            || !is_int($answer['media']) || $answer['media'] < 0 || !is_array($answer['notes']) || !array_is_list($answer['notes'])
            || $answer['warnings'] !== $warnings || $answer['state_dir'] !== '/siterepo/state' || !self::digest($answer['revision_hash'])
            || $answer['initial_code_baseline'] !== null || $answer['initial_publication_cleanup'] !== 'not-applicable') throw new RuntimeException('recovery capture envelope differs');
        foreach ($answer['counts'] as $count) if (!is_int($count) || $count < 0) throw new RuntimeException('recovery capture count malformed');
        if ($answer['counts']['post'] < 2 || $answer['counts']['options'] < 1 || $answer['counts']['deletion'] !== 0) throw new RuntimeException('recovery capture inventory missing');
    }

    public static function assertTransport(string $checkpoint, string $pair, string $stem): void {
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
        $crash = $checkpoint === 'crash';
        $bytes = \WPrismTest\PrivateCommandOutput::readBytes($stem, self::prelude($pair), expectedExit: $crash ? 137 : 0);
        if ($crash) {
            if ($bytes !== '') throw new RuntimeException('interrupted capture emitted unexpected output');
        } else self::assertCapture(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR), $checkpoint);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'crash' && count($argv) === 3) {
            MapRecoveryEvidence::assertCrash(json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR), $argv[2]);
        } elseif (($argv[1] ?? '') === 'transport' && count($argv) === 5) {
            MapRecoveryEvidence::assertTransport($argv[2], $argv[3], $argv[4]);
        } elseif (($argv[1] ?? '') === 'private' && count($argv) === 5) {
            MapRecoveryEvidence::assertPrivate($argv[2], $argv[3], $argv[4]);
        } else throw new RuntimeException('unknown recovery evidence operation');
    } catch (Throwable $failure) {
        fwrite(STDERR, "Map Block recovery evidence refused\n");
        exit(1);
    }
}
