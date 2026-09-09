<?php
declare(strict_types=1);

require_once __DIR__ . '/../native-apply/evidence.php';
require_once __DIR__ . '/corpus.php';

final class QiNativeControlsEvidence {
    public static function native(array $source, array $target, array $before, array $seed, string $saved): void {
        foreach ([$source, $target, $before] as $record) QiNativeApplyEvidence::check(($record['format'] ?? null) === 'wprism-qi-native-apply/v1', 'native gallery record format');
        QiNativeApplyEvidence::check($source['home'] === $seed['home'] && $target['home'] === $before['home']
            && $source['home'] !== $target['home'], 'native gallery home bindings changed');
        QiNativeApplyEvidence::check($source['ids'] === $seed['ids'] && $target['ids'] === $before['ids']
            && $source['uuids'] === $target['uuids'] && $target['uuids'] === $before['uuids'], 'gallery update changed native or durable identities');
        foreach ([$source, $target] as $native) {
            $image = str_replace($seed['home'], $native['home'], $seed['image_url']);
            $expected = $native === $source ? QiConformanceCorpus::body($saved, $native['ids'], $native['home'], $image)
                : QiConformanceCorpus::applied_body($saved, $native['ids'], $native['home'], $image);
            QiNativeApplyEvidence::check($native['page_body'] === $expected, 'complete gallery body differs from independent native fixture expectation');
            $rows = array_column($native['posts'], null, 'ID');
            QiNativeApplyEvidence::check(($rows[$native['ids']['page']]['post_content'] ?? null) === $native['page_body'], 'gallery body differs from its complete stored native row');
        }
        foreach (['home', 'options', 'styles', 'featured', 'attachment', 'uploads'] as $field) {
            QiNativeApplyEvidence::check($target[$field] === $before[$field], 'gallery-only update changed complete native ' . $field);
        }
        $posts = array_column($target['posts'], null, 'ID');
        QiNativeApplyEvidence::check(count($posts) === count($before['posts']), 'gallery-only update created or deleted native posts');
        foreach ($before['posts'] as $post) if ((int) $post['ID'] !== $before['ids']['page']) {
            QiNativeApplyEvidence::check(($posts[$post['ID']] ?? null) === $post, 'gallery-only update changed an unrelated complete native row');
        }
        QiNativeApplyEvidence::check(substr_count($source['page_body'], 'fixture-edit-nonce') === 3
            && !str_contains($target['page_body'], 'nonces') && !str_contains($target['page_body'], 'editLink'),
            'native source must contain three response caches and target must contain none');
    }

    public static function images(string $html, array $native): void {
        QiNativeApplyEvidence::check($html !== '', 'gallery frontend HTML is absent');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            QiNativeApplyEvidence::check($dom->loadHTML($html), 'gallery frontend HTML is absent');
            $xpath = new DOMXPath($dom);
            foreach (QiNativeControlsCorpus::GALLERIES as $name) {
                $class = 'wp-block-' . str_replace('/', '-', $name);
                $owners = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]');
                QiNativeApplyEvidence::check($owners->length === 1, 'frontend gallery owner is absent or duplicated');
                $images = $owners->item(0)->getElementsByTagName('img');
                QiNativeApplyEvidence::check($images->length === 1 && $images->item(0)->getAttribute('src') === $native['attachment']['url'],
                    'frontend gallery does not render its selected original attachment');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

if (($argv[1] ?? null) === '--admit-controls') {
    $root = dirname(__DIR__, 4); $capsule = dirname(__DIR__, 2); $sink = $argv[2]; $pair = $argv[3];
    require_once $root . '/sandbox/tests/lib/agent_version.php';
    require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $read = static fn(string $name, string $verb = ''): array => QiNativeApplyEvidence::object($sink . '/' . $name, $pair, $root, $verb);
    $source = $read('controls-source'); $target = $read('controls-target');
    $saved = QiNativeControlsCorpus::saved(file_get_contents($capsule . '/fixtures/native-blocks.html'), file_get_contents(__DIR__ . '/blocks.html'));
    QiNativeControlsEvidence::native($source, $target, $read('target2'), $read('seed1'), $saved);
    QiNativeApplyEvidence::content_update($read('controls-apply', 'apply'), $read('controls-repeat', 'apply'));
    QiNativeApplyEvidence::check($target === $read('controls-stable') && $source === $read('controls-source-stable'),
        'gallery repeat or frontend changed complete native state');
    $html = [];
    foreach ([1 => $source, 2 => $target] as $side => $native) {
        $html[$side] = QiNativeApplyEvidence::stream($sink . '/controls-http' . $side, $pair, $root);
        QiNativeControlsEvidence::images($html[$side], $native);
        QiNativeApplyEvidence::check($read('controls-image' . $side) === ['status' => 200]
            && hash_file('sha256', $sink . '/controls-image' . $side . '.png') === $native['uploads'][$native['attachment']['file']]['sha256'],
            'selected gallery HTTP image differs from the native attachment');
    }
    QiNativeApplyEvidence::css($html[1], $html[2], $source, $target);
    foreach (['controls-capture', 'controls-recapture', 'controls-source-repeat'] as $stage) {
        $capture = $read($stage, 'capture');
        QiNativeApplyEvidence::check($capture['warnings'] === [] && $capture['notes'] === [] && $capture['media'] === 1, 'gallery capture has an unproved diagnostic or media catalog');
    }
    $compiled = [];
    foreach (['controls-source', 'controls-input', 'controls-target', 'controls-source-repeat'] as $side) {
        $repo = $sink . '/' . $side;
        $policy = WPrism\Policy::load($repo, adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
        $compiled[$side] = WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
        if ($side !== 'controls-source') WPrismTest\RepositoryConvergence::assertSame($compiled['controls-source'], $compiled[$side]);
    }
    echo json_encode(['format' => 'wprism-qi-native-gallery-admission/v1', 'result' => 'pass', 'galleries' => 3,
        'compiled_entities' => 7, 'applied' => 1, 'repeat_applied' => 0,
        'scope' => 'Three real picker gallery fixtures through native REST Save, cross-ID Apply, full recapture, HTTP images/CSS and native-state preservation. Browser interaction and other media controls are separate.'], JSON_THROW_ON_ERROR), "\n";
}
