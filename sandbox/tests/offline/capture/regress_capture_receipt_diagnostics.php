<?php
declare(strict_types=1);

namespace WPrism {
    // The same durable inode becomes visible after all three receipt reads.
    // This models the documented bind-mount window without deleting evidence
    // or changing production retry policy.
    function lstat(string $path): array|false {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '';
        if (($GLOBALS['receipt_probe_path'] ?? null) === $path) {
            if ($caller === 'read_record' && ($GLOBALS['receipt_probe_mode'] ?? '') === 'delayed') {
                $GLOBALS['receipt_probe_reads']++;
                return false;
            }
            if ($caller === 'receipt_intent_refusal' && ($GLOBALS['receipt_probe_mode'] ?? '') === 'diagnostic-throw') {
                throw new \RuntimeException('private metadata failure canary');
            }
        }
        return \lstat($path);
    }
}

namespace {
    final class WP_CLI {
        public static function add_command(string $name, string $class): void {}
    }
    require_once dirname(__DIR__, 2) . '/lib/check.php';
    require_once dirname(__DIR__, 2) . '/lib/ShellProbe.php';
    require_once dirname(__DIR__, 2) . '/lib/PrivateRefusalReceipt.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Publication/PublicationJournal.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Command/Cli.php';

    use WPrism\Canon;
    use WPrism\CommandRefusalException;
    use WPrism\PublicationJournal as Journal;
    use WPrism\PrivateRefusalEvidence;

    $scratch = sys_get_temp_dir() . '/wprism-receipt-diagnostics-' . bin2hex(random_bytes(8));
    mkdir($scratch, 0700);
    $remove = static function (string $directory) use (&$remove): void {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') continue;
            $file = $directory . '/' . $name;
            if (is_dir($file) && !is_link($file)) $remove($file);
            else unlink($file);
        }
        rmdir($directory);
    };
    $seal = static function (array $record): string {
        unset($record['record_sha256']);
        $record['record_sha256'] = hash('sha256', Canon::encode($record));
        return Canon::encode($record);
    };
    try {
        foreach (['missing', 'delayed', 'identity', 'phase', 'record', 'long-field', 'diagnostic-throw'] as $case) {
            $repo = $scratch . '/' . $case;
            mkdir($repo, 0700);
            file_put_contents($repo . '/site.wprism.json', '{}');
            $state = $repo . '/state';
            mkdir(Journal::stage_dir($state));
            file_put_contents(Journal::stage_dir($state) . '/content.json', '{"private":"content-canary"}');
            $intent = Journal::begin_intent($state, Journal::stage_dir($state));
            Journal::swap($state, true);
            $intent = Journal::mark_swapped($state, $intent);
            $intent = Journal::mark_commit_ready($state, $intent);
            $intent = Journal::mark_committing($state, $intent);
            $changed = $intent;
            if ($case === 'identity') $changed['id'] = str_repeat('b', 32);
            if ($case === 'phase') $changed['phase'] = 'ready';
            if (in_array($case, ['record', 'diagnostic-throw'], true)) $changed['created_at'] = 'changed';
            if ($case === 'long-field') $changed['created_at'] = str_repeat('private-long-canary', 1000);
            file_put_contents(Journal::intent_path($state), $seal($changed));
            if ($case === 'missing') unlink(Journal::intent_path($state));
            $prior = is_file(Journal::intent_path($state)) ? file_get_contents(Journal::intent_path($state)) : null;
            $GLOBALS['receipt_probe_path'] = Journal::intent_path($state);
            $GLOBALS['receipt_probe_mode'] = $case;
            $GLOBALS['receipt_probe_reads'] = 0;
            $failure = null;
            try { Journal::write_receipt($state, $intent); }
            catch (Throwable $caught) { $failure = $caught; }
            $GLOBALS['receipt_probe_mode'] = '';
            wprism_check($failure instanceof CommandRefusalException && $failure->reasonCode === 'capture_recovery_ambiguous', "$case: actual receipt publication keeps its typed refusal");
            if (!$failure instanceof CommandRefusalException) continue;
            $reason = $case === 'phase' ? 'capture cannot write its receipt before the durable COMMIT-attempt marker'
                : 'capture cannot write its receipt because its durable intent is missing or changed';
            $original = CommandRefusalException::ambiguousCaptureRecovery('wprism: capture recovery is blocked by an ambiguous publication/COMMIT boundary — '
                . $reason . '; refusing to retry or discard the retained backup. Inspect the intent, receipt, and database before continuing.');
            wprism_check_same($original->payload(), $failure->payload(), "$case: complete public refusal payload is byte-equivalent");
            wprism_check_same($original->getMessage(), $failure->getMessage(), "$case: operator sentence is unchanged");
            wprism_check(!str_contains((string) $failure, 'content-canary') && !str_contains((string) $failure, 'canonical_sha256')
                && !str_contains((string) $failure, 'private-long-canary'), "$case: ordinary exception rendering cannot reveal private facts");
            wprism_check_same($prior, is_file(Journal::intent_path($state)) ? file_get_contents(Journal::intent_path($state)) : null,
                "$case: refused publication preserves exact intent bytes/absence");
            wprism_check(!file_exists(Journal::receipt_path($state)) && file_get_contents($state . '/content.json') === '{"private":"content-canary"}',
                "$case: diagnostic capture neither publishes a receipt nor changes content");
            $graph = PrivateRefusalEvidence::graph($failure);
            $contexts = [];
            foreach ($graph['throwable'] as $node) {
                $data = json_decode($node['message'], true);
                if (is_array($data) && isset($data['format'])) $contexts[$data['format']] = $data;
            }
            if ($case === 'diagnostic-throw') {
                wprism_check($contexts === [], 'diagnostic failure cannot replace the original refusal');
                continue;
            }
            $context = $contexts['wprism-publication-receipt-intent-diagnostic/v1'] ?? [];
            $decision = match ($case) {'missing', 'delayed' => 'missing_intent', 'identity' => 'intent_id_mismatch', 'phase' => 'intent_phase_mismatch', default => 'intent_record_mismatch'};
            wprism_check_same($decision, $context['decision'] ?? null, "$case: private evidence distinguishes the actual refusal decision");
            wprism_check_same(hash('sha256', Canon::encode($intent)), $context['expected']['canonical_sha256'] ?? null,
                "$case: private evidence binds the expected sealed committing intent");
            wprism_check_same(in_array($case, ['missing', 'delayed'], true) ? null : hash('sha256', (string) $prior),
                $context['observed']['canonical_sha256'] ?? null, "$case: retained observation is the refused read, not a later replacement");
            $metadata = $contexts['wprism-publication-path-metadata-diagnostic/v1']['paths'] ?? [];
            wprism_check(count($metadata) === 10 && ($case === 'missing' ? ($metadata['intent'] ?? null) === null
                : ($metadata['intent']['ino'] ?? null) === lstat(Journal::intent_path($state))['ino']), "$case: bounded topology captures metadata without content reads");
            if ($case === 'delayed') wprism_check_same(3, $GLOBALS['receipt_probe_reads'], 'diagnostics do not retry a missing receipt intent into success');
            if ($case === 'long-field') wprism_check_same(hash('sha256', Canon::encode($changed['created_at'])),
                $context['observed']['created_at']['value_sha256'] ?? null, 'oversized observed fields are bounded fingerprints');
            wprism_check(($graph['traversal']['scan_complete'] ?? false) && max(array_column($graph['throwable'], 'message_original_bytes')) <= 4096,
                "$case: diagnostic graph is complete within the existing private field limit");
            // The real plain-output CLI refusal path records private causes;
            // destroying the disposable source then cannot erase this copy.
            $halt = new ReflectionMethod(WPrism\Cli::class, 'halt_json_failure');
            $halt->invoke(null, $failure, ['repo' => $repo], 'capture');
            $records = glob($repo . '/.wprism/refusals/*') ?: [];
            wprism_check(count($records) === 1, "$case: actual CLI refusal recorder persists one capture record");
            if ($case === 'delayed' && count($records) === 1) $nativeSeed = [
                'record' => json_decode(file_get_contents($records[0]), true),
                'facts' => ['intent' => $intent, 'paths' => $metadata, 'intent_sha256' => hash('sha256', (string) $prior), 'intent_inode' => lstat(Journal::intent_path($state))['ino'],
                    'phase' => 'committing', 'committed' => true, 'tree_sha256' => Journal::tree_digest($state)],
                'public' => $failure->payload(),
            ];
            if (count($records) === 1) wprism_check_same($graph['throwable'], json_decode(file_get_contents($records[0]), true)['throwable'],
                "$case: persisted private record contains the exact engine diagnostic graph");
        }

        if (isset($nativeSeed)) foreach (['valid', 'wrong-cause', 'wrong-class', 'omitted-node', 'wrong-intent', 'wrong-inode', 'no-commit', 'changed-tree', 'changed-repeat', 'bad-message-hash', 'stderr-warning'] as $fault) {
            $directory = $scratch . '/native-' . $fault;
            mkdir($directory, 0700);
            mkdir($directory . '/records', 0700);
            $record = $nativeSeed['record'];
            $facts = $nativeSeed['facts'];
            if ($fault === 'wrong-cause') {
                $context = json_decode($record['throwable'][2]['message'], true);
                $context['decision'] = 'intent_id_mismatch';
                $record['throwable'][2]['message'] = Canon::encode($context);
            }
            if ($fault === 'bad-message-hash') $record['throwable'][2]['message_sha256'] = str_repeat('a', 64);
            if ($fault === 'wrong-class') $record['throwable'][1]['class'] = 'RuntimeException';
            if ($fault === 'omitted-node') $record['traversal']['omitted_scanned_nodes'] = 1;
            if ($fault === 'wrong-intent') $facts['intent_sha256'] = str_repeat('a', 64);
            if ($fault === 'wrong-inode') $facts['intent_inode']++;
            if ($fault === 'no-commit') $facts['committed'] = false;
            if ($fault === 'changed-tree') $facts['tree_sha256'] = str_repeat('a', 64);
            $recordFile = $directory . '/records/20260914-120001-capture-aaaaaaaaaaaaaaaaaaaaaaaa.json';
            file_put_contents($recordFile, json_encode($record, JSON_THROW_ON_ERROR));
            chmod($recordFile, 0600);
            $diagnostic = WPrismTest\PrivateRefusalReceipt::diagnosticNewRecords($directory . '/records', '[]', 'capture');
            foreach (['command' => json_encode($nativeSeed['public']), 'private' => $diagnostic,
                'observed' => json_encode($facts), 'repeated' => json_encode($fault === 'changed-repeat' ? $nativeSeed['facts'] + ['unexpected' => true] : $facts)] as $stage => $bytes) {
                foreach (['stdout' => $bytes, 'stderr' => $stage === 'observed' && $fault === 'stderr-warning' ? "PHP Warning: native canary\n" : '',
                    'exit' => ($stage === 'command' ? '1' : '0') . "\n"] as $suffix => $value) {
                    file_put_contents($directory . '/' . $stage . '.' . $suffix, $value);
                    chmod($directory . '/' . $stage . '.' . $suffix, 0600);
                }
            }
            [$status, $stdout] = WPrismTest\ShellProbe::run('exec "$1" "$2" verify "$3" receiptprobe "$3"',
                [PHP_BINARY, dirname(__DIR__, 2) . '/live/fixtures/capture_receipt_readback.php', $directory], dirname(__DIR__, 4));
            wprism_check($fault === 'valid' ? $status === 0 && str_contains($stdout, 'PASS: native missing receipt read')
                : $status !== 0 && !str_contains($stdout, 'PASS:'), "$fault: real native verifier requires complete independent commit/intent/topology observations");
        }

    } finally {
        $remove($scratch);
    }
    wprism_check_summary('capture receipt diagnostics');
}
