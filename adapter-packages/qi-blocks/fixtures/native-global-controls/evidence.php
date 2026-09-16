<?php
declare(strict_types=1);

require_once __DIR__ . '/../native-apply/evidence.php';
require_once __DIR__ . '/corpus.php';

final class QiNativeGlobalControlsEvidence {
    public static function frontend(string $sourceHtml, string $targetHtml, array $source, array $target, array $case): void {
        $fragments = [];
        $coordinates = [];
        foreach ([[$sourceHtml, $source], [$targetHtml, $target]] as [$html, $native]) {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new DOMDocument();
                QiNativeApplyEvidence::check($html !== '' && $dom->loadHTML($html), 'global-control native frontend is absent');
                $owners = (new DOMXPath($dom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," qodef-block-915bb80f ")]');
                $hidden = ($case['metadata']['blockVisibility'] ?? null) === false;
                QiNativeApplyEvidence::check($owners->length === ($hidden ? 0 : 1), 'global-control frontend lost, duplicated or failed to omit the selected owner');
                if (!$hidden) {
                    $images = $owners->item(0)->getElementsByTagName('img');
                    QiNativeApplyEvidence::check($images->length === 1, 'visible global-control owner must retain exactly one selected image');
                    $image = $images->item(0);
                    $attachment = $native['attachment'];
                    $metadata = $attachment['metadata'];
                    QiNativeApplyEvidence::check(in_array('wp-image-' . $native['ids']['image'], preg_split('/\s+/', $image->getAttribute('class')), true)
                        && $image->getAttribute('src') === $attachment['url']
                        && $image->getAttribute('width') === (string) $metadata['width']
                        && $image->getAttribute('height') === (string) $metadata['height'], 'global-control image lost its independently observed native coordinates or dimensions');
                    // The retained 1200x800 writer emits four WordPress srcset
                    // widths. Bind every advertised URL to native metadata and
                    // uploads, rather than normalizing arbitrary home strings.
                    $files = [$metadata['file'] => $metadata['width']];
                    foreach ($metadata['sizes'] as $size) $files[dirname($metadata['file']) . '/' . $size['file']] = $size['width'];
                    $urls = [];
                    foreach ($files as $file => $width) $urls[$native['home'] . '/wp-content/uploads/' . $file] = [$file, $width];
                    $widths = [];
                    $selected = [];
                    foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                        QiNativeApplyEvidence::check(preg_match('/\A(\S+) ([1-9][0-9]*)w\z/', trim($candidate), $match) === 1, 'native responsive image candidate is malformed or absent');
                        $binding = $urls[$match[1]] ?? null;
                        QiNativeApplyEvidence::check(is_array($binding) && (string) $binding[1] === $match[2]
                            && ($native['uploads'][$binding[0]]['width'] ?? null) === $binding[1]
                            && !isset($selected[$binding[0]]), 'responsive image URL is absent, duplicated or disagrees with native metadata and uploads');
                        $selected[$binding[0]] = $match[1];
                        $widths[] = $binding[1];
                    }
                    sort($widths, SORT_NUMERIC);
                    QiNativeApplyEvidence::check($widths === [300, 768, 1024, 1200], 'global-control image lost the complete observed responsive width roster');
                    ksort($selected, SORT_STRING);
                    $coordinates[] = $selected;
                }
                $fragments[] = $hidden ? '' : $dom->saveHTML($owners->item(0));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $rebound = $fragments[1];
        if ($coordinates !== []) {
            QiNativeApplyEvidence::check(array_keys($coordinates[0]) === array_keys($coordinates[1]), 'native responsive image file bindings differ across sites');
            $rebound = strtr($rebound, array_combine(array_values($coordinates[1]), array_values($coordinates[0])));
        }
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
