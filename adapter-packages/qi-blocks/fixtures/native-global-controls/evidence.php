<?php
declare(strict_types=1);

require_once __DIR__ . '/../native-apply/evidence.php';
require_once __DIR__ . '/corpus.php';

final class QiNativeGlobalControlsEvidence {
    public static function frontend(string $sourceHtml, string $targetHtml, array $source, array $target, array $case): void {
        $fragments = [];
        foreach ([$sourceHtml, $targetHtml] as $html) {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new DOMDocument();
                QiNativeApplyEvidence::check($html !== '' && $dom->loadHTML($html), 'global-control native frontend is absent');
                $owners = (new DOMXPath($dom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," qodef-block-915bb80f ")]');
                $hidden = ($case['metadata']['blockVisibility'] ?? null) === false;
                QiNativeApplyEvidence::check($owners->length === ($hidden ? 0 : 1), 'global-control frontend lost, duplicated or failed to omit the selected owner');
                $fragments[] = $hidden ? '' : $dom->saveHTML($owners->item(0));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $rebound = str_replace($target['attachment']['url'], $source['attachment']['url'], $fragments[1]);
        $rebound = preg_replace('/(?<![A-Za-z0-9_-])wp-image-' . $target['ids']['image'] . '(?![0-9])/', 'wp-image-' . $source['ids']['image'], $rebound);
        QiNativeApplyEvidence::check($fragments[0] === $rebound, 'complete native global-control element differs beyond its proven image coordinates');
        QiNativeApplyEvidence::css($sourceHtml, $targetHtml, $source, $target);
    }
}

if (($argv[1] ?? null) === '--admit-global') {
    $root = dirname(__DIR__, 4);
    $capsule = dirname(__DIR__, 2);
    $sink = $argv[2];
    $pair = $argv[3];
    $name = $argv[4];
    $beforeName = $argv[5];
    require_once $root . '/sandbox/tests/lib/agent_version.php';
    require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $read = static fn(string $suffix, string $verb = ''): array => QiNativeApplyEvidence::object($sink . '/' . $suffix, $pair, $root, $verb);
    $recordBytes = file_get_contents(__DIR__ . '/observations.json');
    $case = QiNativeGlobalControlsCorpus::cases($recordBytes)[$name];
    $saved = QiNativeGlobalControlsCorpus::saved(file_get_contents($capsule . '/fixtures/native-blocks.html'), $recordBytes, $name);
    $source = $read($name . '-source');
    $target = $read($name . '-target');
    QiNativeApplyEvidence::content_native($source, $target, $read($beforeName), $read('seed1'), $saved);
    QiNativeApplyEvidence::content_update($read($name . '-apply', 'apply'), $read($name . '-repeat', 'apply'));
    QiNativeApplyEvidence::check($source === $read($name . '-source-stable') && $target === $read($name . '-stable'), 'global repeat or frontend changed complete native state');
    QiNativeGlobalControlsEvidence::frontend(QiNativeApplyEvidence::stream($sink . '/' . $name . '-http1', $pair, $root),
        QiNativeApplyEvidence::stream($sink . '/' . $name . '-http2', $pair, $root), $source, $target, $case);
    foreach (['capture', 'recapture', 'source-repeat'] as $stage) {
        $capture = $read($name . '-' . $stage, 'capture');
        QiNativeApplyEvidence::check($capture['warnings'] === [] && $capture['notes'] === [] && $capture['media'] === 1, 'global capture has an unproved diagnostic or media roster');
    }
    $compiled = [];
    foreach (['source', 'input', 'target', 'source-repeat'] as $side) {
        $repo = $sink . '/' . $name . '-' . $side;
        $policy = WPrism\Policy::load($repo, adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
        $compiled[$side] = WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
        if ($side !== 'source') WPrismTest\RepositoryConvergence::assertSame($compiled['source'], $compiled[$side]);
    }
    echo json_encode(['format' => 'wprism-qi-native-global-admission/v1', 'case' => $name, 'result' => 'pass', 'compiled_entities' => 7,
        'applied' => 1, 'repeat_applied' => 0, 'scope' => 'Retained native writer fragment replay through REST Save, divergent-ID Apply, complete canonical recapture, frontend owner/CSS and native state preservation. Target browser controls and computed viewport visibility remain separate.'], JSON_THROW_ON_ERROR), "\n";
}
