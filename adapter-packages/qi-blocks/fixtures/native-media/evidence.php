<?php
declare(strict_types=1);

require_once __DIR__ . '/../native-apply/evidence.php';
require_once __DIR__ . '/corpus.php';

final class QiNativeMediaEvidence {
    public static function served(array $image, array $receipt, string $bytes, string $html): array {
        QiNativeApplyEvidence::check($receipt === ['status' => 200], 'selected image did not return native HTTP 200');
        QiNativeApplyEvidence::check(hash('sha256', $bytes) === $image['bytes_sha256'], 'HTTP image bytes differ from the decoded native file');
        QiNativeApplyEvidence::check(str_contains($html, 'src="' . $image['url'] . '"'), 'frontend omitted a selected native image');
        return $image + ['status' => $receipt['status']];
    }

    public static function http(array $source, array $target, array $sourceNative, array $targetNative): void {
        foreach ([[$source, $sourceNative], [$target, $targetNative]] as [$http, $native]) {
            QiNativeApplyEvidence::check(($http['format'] ?? null) === 'wprism-qi-native-media-pixels/v1'
                && $http['home'] === $native['home'] && $http['page'] === $native['ids']['page']
                && $http['attachment'] === $native['ids']['image'], 'crop HTTP observation has the wrong native owner');
            QiNativeApplyEvidence::check(($http['original_samples'] ?? null) === [[0, 0, 0, 0], [255, 0, 0, 0], [0, 255, 173, 0], [255, 255, 173, 0], [127, 127, 0, 0]],
                'crop HTTP observation lacks its independent asymmetric original premise');
            QiNativeApplyEvidence::check(count($http['images']) === 4, 'crop HTTP observation must retain all four consumers');
            foreach (QiNativeMediaCorpus::DIMENSIONS as $i => [$width, $height]) {
                $row = $http['images'][$i];
                $url = substr($native['attachment']['url'], 0, -4) . '-' . $width . 'x' . $height . '.png';
                QiNativeApplyEvidence::check([$row['width'], $row['height'], $row['url'], $row['status']] === [$width, $height, $url, 200],
                    'selected native crop URL, HTTP status or dimensions differ');
                foreach (['bytes_sha256', 'pixels_sha256'] as $key) QiNativeApplyEvidence::check(is_string($row[$key])
                    && preg_match('/^[a-f0-9]{64}$/D', $row[$key]) === 1, 'crop HTTP pixel or encoding digest is absent');
            }
        }
        QiNativeApplyEvidence::check(array_column($source['images'], 'pixels_sha256') === array_column($target['images'], 'pixels_sha256'),
            'decoded native source and target crop pixels differ');
    }

    public static function native(array $source, array $target, array $before, array $seed, string $saved): void {
        foreach ([$source, $target, $before] as $record) QiNativeApplyEvidence::check(($record['format'] ?? null) === 'wprism-qi-native-apply/v1', 'native crop record format');
        QiNativeApplyEvidence::check($source['home'] === $seed['home'] && $target['home'] === $before['home']
            && $source['home'] !== $target['home'], 'native crop home bindings changed');
        QiNativeApplyEvidence::check($source['ids'] === $seed['ids'] && $target['ids'] === $before['ids']
            && $source['uuids'] === $target['uuids'] && $target['uuids'] === $before['uuids'], 'crop-only update changed durable identity');
        foreach ([$source, $target] as $native) {
            $image = str_replace($seed['home'], $native['home'], $seed['image_url']);
            $expected = $native === $source ? QiConformanceCorpus::body($saved, $native['ids'], $native['home'], $image)
                : QiConformanceCorpus::applied_body($saved, $native['ids'], $native['home'], $image);
            QiNativeApplyEvidence::check($native['page_body'] === $expected, 'complete crop body differs from native fixture expectation');
            $rows = array_column($native['posts'], null, 'ID');
            QiNativeApplyEvidence::check(($rows[$native['ids']['page']]['post_content'] ?? null) === $native['page_body'], 'native crop body disagrees with its complete stored row');
        }
        QiNativeApplyEvidence::check($target['options'] === $before['options'] && $target['styles'] === $before['styles'], 'crop-only update changed complete native Qi options');
        $posts = array_column($target['posts'], null, 'ID');
        QiNativeApplyEvidence::check(count($posts) === count($before['posts']), 'crop-only update created or deleted native posts');
        foreach ($before['posts'] as $post) if ((int) $post['ID'] !== $before['ids']['page']) {
            QiNativeApplyEvidence::check(($posts[$post['ID']] ?? null) === $post, 'crop-only update changed an unselected complete native row');
        }
        QiNativeApplyEvidence::check($target['featured'] === $before['featured'], 'crop-only update changed featured image selection');
        $attachment = $target['attachment']; $sizes = $attachment['metadata']['sizes'];
        $priorSizes = $before['attachment']['metadata']['sizes'];
        foreach ($priorSizes as $key => $size) QiNativeApplyEvidence::check(($sizes[$key] ?? null) === $size, 'stock native image size changed');
        $generated = array_diff_key($sizes, $priorSizes);
        QiNativeApplyEvidence::check(count($generated) === 4, 'native metadata does not own all four crops');
        $attachment['metadata']['sizes'] = $priorSizes;
        QiNativeApplyEvidence::check($attachment === $before['attachment'], 'crop update changed native original attachment metadata');
        $extraFiles = array_diff_key($target['uploads'], $before['uploads']);
        QiNativeApplyEvidence::check(count($extraFiles) === 4, 'target generated an incomplete or widened file roster');
        foreach ($before['uploads'] as $path => $file) QiNativeApplyEvidence::check(($target['uploads'][$path] ?? null) === $file, 'existing target upload bytes changed');
        foreach (QiNativeMediaCorpus::DIMENSIONS as [$width, $height]) {
            $path = substr($target['attachment']['file'], 0, -4) . '-' . $width . 'x' . $height . '.png';
            $file = $extraFiles[$path] ?? [];
            QiNativeApplyEvidence::check([$file['width'] ?? null, $file['height'] ?? null, $file['mime'] ?? null] === [$width, $height, 'image/png'], 'native target crop file is absent or has wrong dimensions');
            $matches = array_filter($generated, static fn(array $size): bool => $size['file'] === basename($path));
            QiNativeApplyEvidence::check(count($matches) === 1, 'generated crop metadata is missing or ambiguous');
            $key = array_key_first($matches); $size = $matches[$key];
            QiNativeApplyEvidence::check(preg_match('/^wprism_recipe_[a-f0-9]{64}$/D', $key) === 1
                && $size === ['file' => basename($path), 'width' => $width, 'height' => $height, 'mime-type' => 'image/png', 'filesize' => $file['bytes']], 'crop metadata does not match its native file');
        }
    }

}

if (($argv[1] ?? null) === '--admit-media') {
    $root = dirname(__DIR__, 4); $capsule = dirname(__DIR__, 2); $sink = $argv[2]; $pair = $argv[3];
    require_once $root . '/sandbox/tests/lib/agent_version.php';
    require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $read = static fn(string $name, string $verb = ''): array => QiNativeApplyEvidence::object($sink . '/' . $name, $pair, $root, $verb);
    $source = $read('media-source'); $target = $read('media-target');
    $saved = QiNativeMediaCorpus::saved(file_get_contents($capsule . '/fixtures/native-blocks.html'), file_get_contents(__DIR__ . '/blocks.html'));
    QiNativeMediaEvidence::native($source, $target, $read('target2'), $read('seed1'), $saved);
    $http = [];
    foreach ([1, 2] as $side) {
        $http[$side] = $read('media-pixels' . $side);
        $html = QiNativeApplyEvidence::stream($sink . '/media-page-http' . $side, $pair, $root);
        foreach ($http[$side]['images'] as $i => &$image) {
            $image = QiNativeMediaEvidence::served($image, $read('media-image' . $side . '-' . $i),
                file_get_contents($sink . '/media-image' . $side . '-' . $i . '.png'), $html);
        }
        unset($image);
    }
    QiNativeMediaEvidence::http($http[1], $http[2], $source, $target);
    QiNativeApplyEvidence::content_update($read('media-apply', 'apply'), $read('media-repeat', 'apply'));
    QiNativeApplyEvidence::check($target === $read('media-stable'), 'repeat or HTTP changed complete native target state');
    foreach (['media-capture', 'media-recapture', 'media-source-repeat'] as $name) {
        $capture = $read($name, 'capture');
        QiNativeApplyEvidence::check($capture['warnings'] === [] && $capture['notes'] === [] && $capture['media'] === 1, 'crop capture has an unproved diagnostic or media catalog');
    }
    $compiled = [];
    foreach (['media-source', 'media-input', 'media-target', 'media-source-repeat'] as $side) {
        $repo = $sink . '/' . $side;
        $policy = WPrism\Policy::load($repo, adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
        $compiled[$side] = WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
        QiNativeApplyEvidence::check(count($compiled[$side]->media_derivatives()) === 4, 'compiled crop inventory is incomplete');
        if ($side !== 'media-source') WPrismTest\RepositoryConvergence::assertSame($compiled['media-source'], $compiled[$side]);
    }
    QiNativeApplyEvidence::css(QiNativeApplyEvidence::stream($sink . '/media-page-http1', $pair, $root), QiNativeApplyEvidence::stream($sink . '/media-page-http2', $pair, $root), $source, $target);
    echo json_encode(['format' => 'wprism-qi-native-media-admission/v1', 'result' => 'pass', 'crop_consumers' => 4,
        'dimensions' => QiNativeMediaCorpus::DIMENSIONS, 'compiled_entities' => 7, 'applied' => 1, 'repeat_applied' => 0,
        'scope' => 'Native saved crop fixture, content-only Apply, four selected HTTP images, decoded dimensions/pixels, metadata ownership, CSS and complete recapture. Browser interaction and broader readiness are separate.'], JSON_THROW_ON_ERROR), "\n";
}
