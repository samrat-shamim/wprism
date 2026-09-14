<?php
declare(strict_types=1);

namespace WPrism {
    // Loaded only by the owned native probe through WP-CLI --require. Hide
    // precisely the receipt reader's stat observations, retaining the real
    // committed inode for independent post-refusal inspection. No deletion,
    // recovery replay, or production fault switch is added to the engine.
    function lstat(string $path): array|false {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        if (getenv('WPRISM_RECEIPT_READBACK_PROBE') === 'missing'
            && $path === '/siterepo/state.capture-intent'
            && ($trace[1]['function'] ?? '') === 'read_record'
            && in_array('write_receipt', array_column($trace, 'function'), true)) {
            return false;
        }
        return \lstat($path);
    }
}

namespace {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        require_once dirname(__DIR__, 2) . '/lib/PrivateCommandOutput.php';
        require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
        require_once dirname(__DIR__, 2) . '/lib/PrivateRefusalReceipt.php';
        [$mode, $sink, $pair, $observation] = array_slice($argv, 1);
        if ($mode !== 'verify' || preg_match('/\A[a-z][a-z0-9]*\z/', $pair) !== 1) throw new RuntimeException('invalid native receipt probe binding');
        $pattern = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli1-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $read = static fn(string $stem, int $exit = 0): array => json_decode(WPrismTest\PrivateCommandOutput::readObject(
            $stem, $pattern, WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE, $exit), true, 32, JSON_THROW_ON_ERROR);
        $check = static function (bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); };
        $public = $read($sink . '/command', 1);
        $check(($public['error'] ?? null) === 'capture_recovery_ambiguous', 'native Capture must refuse with its exact public cause');
        $diagnostic = $read($sink . '/private');
        WPrismTest\PrivateRefusalReceipt::assertDiagnostic($diagnostic, 'capture');
        $check(count($diagnostic['records']) === 1, 'one new native Capture refusal is retained');
        $facts = $read($observation . '/observed');
        $repeat = $read($observation . '/repeated');
        $check($facts['intent_sha256'] === hash('sha256', WPrism\Canon::encode($facts['intent'])), 'native intent bytes match their independently retained digest');
        $expected = ['canonical_sha256' => $facts['intent_sha256']] + array_intersect_key($facts['intent'],
            array_fill_keys(['format', 'id', 'phase', 'candidate_sha256', 'previous_sha256', 'created_at', 'record_sha256'], true));
        $context = ['format' => 'wprism-publication-receipt-intent-diagnostic/v1',
            'decision' => 'missing_intent', 'expected' => $expected, 'observed' => null];
        $metadata = ['format' => 'wprism-publication-path-metadata-diagnostic/v1', 'paths' => $facts['paths']];
        WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, [
            'command' => 'capture', 'reason_code' => 'capture_recovery_ambiguous', 'nodes' => [
                ['class' => 'WPrism\\CommandRefusalException', 'parent_index' => null, 'relation' => 'root',
                    'message' => 'wprism: capture recovery is blocked by an ambiguous publication/COMMIT boundary — capture cannot write its receipt because its durable intent is missing or changed; refusing to retry or discard the retained backup. Inspect the intent, receipt, and database before continuing.'],
                ['class' => 'WPrism\\PrivateEvidenceException', 'parent_index' => 0, 'relation' => 'previous',
                    'message' => 'publication receipt intent diagnostics retained privately'],
                ['class' => 'RuntimeException', 'parent_index' => 1, 'relation' => 'private_evidence', 'message' => WPrism\Canon::encode($context)],
                ['class' => 'RuntimeException', 'parent_index' => 1, 'relation' => 'private_evidence', 'message' => WPrism\Canon::encode($metadata)],
            ],
        ]);
        $check($facts === $repeat, 'two read-only native observations preserve publication evidence');
        $check($context['format'] === 'wprism-publication-receipt-intent-diagnostic/v1'
            && $context['decision'] === 'missing_intent' && $context['observed'] === null
            && $context['expected']['canonical_sha256'] === $facts['intent_sha256'], 'exact missing read and independently retained committing intent');
        $check($metadata['format'] === 'wprism-publication-path-metadata-diagnostic/v1'
            && $metadata['paths']['intent']['ino'] === $facts['intent_inode']
            && $metadata['paths']['receipt'] === null, 'native inode remains present after the refused read; no receipt was published');
        $check($facts['committed'] === true && $facts['phase'] === 'committing'
            && $facts['tree_sha256'] === $context['expected']['candidate_sha256'], 'database commit proof and published tree bind the same refused intent');
        echo "PASS: native missing receipt read preserves exact private diagnostics after owned teardown\n";
        return;
    }
    if (($args[0] ?? null) !== 'observe') return;
    require_once WP_CONTENT_DIR . '/mu-plugins/wprism/src/Capture/CapturePublicationRecovery.php';
    $state = '/siterepo/state';
    $intent = WPrism\PublicationJournal::intent_record($state);
    if (!is_array($intent)) throw new RuntimeException('expected retained native intent');
    $paths = ['intent' => WPrism\PublicationJournal::intent_path($state), 'receipt' => WPrism\PublicationJournal::receipt_path($state)];
    foreach ($paths as $name => $path) {
        $paths[$name . '_previous'] = $path . '.previous';
        $paths[$name . '_next'] = $path . '.next';
    }
    $paths += ['lock' => WPrism\PublicationJournal::lock_path($state), 'state' => $state,
        'staging' => WPrism\PublicationJournal::stage_dir($state), 'backup' => WPrism\PublicationJournal::backup_dir($state)];
    $metadata = [];
    foreach ($paths as $name => $path) {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $metadata[$name] = is_array($stat) ? array_intersect_key($stat,
            array_fill_keys(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'], true)) : null;
    }
    echo json_encode([
        'intent' => $intent, 'paths' => $metadata,
        'intent_sha256' => hash('sha256', WPrism\Canon::encode($intent)),
        'intent_inode' => lstat(WPrism\PublicationJournal::intent_path($state))['ino'],
        'phase' => $intent['phase'],
        'committed' => WPrism\CapturePublicationRecovery::commitStatus($state, $intent),
        'tree_sha256' => WPrism\PublicationJournal::tree_digest($state),
    ], JSON_THROW_ON_ERROR), "\n";
}
