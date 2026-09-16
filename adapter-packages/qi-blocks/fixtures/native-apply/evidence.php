<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PhpContainerValue.php';
require_once dirname(__DIR__) . '/conformance/corpus.php';

/** Native facts and corpus expectations do not consult adapter declarations. */
final class QiNativeApplyEvidence {
    public static function check(bool $ok, string $why): void {
        if (!$ok) throw new RuntimeException('Qi native Apply evidence: ' . $why);
    }

    public static function stream(string $stem, string $pair, string $root, string $verb = ''): string {
        $pattern = ' ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-z0-9]+ (?:Creating|Created) *';
        if ($verb !== '') $pattern .= '|' . preg_quote('private command diagnostics (unverified): ' . $root
            . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.', '/') . '[A-Za-z0-9]{6}';
        $bytes = WPrismTest\PrivateCommandOutput::readBytes($stem, '/\A(?:' . $pattern . ')\z/', WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
        self::check(preg_match('/(?:PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error)|(?:Fatal error|Warning|Notice|Deprecated|Parse error):)/', $bytes) !== 1,
            'command emitted a diagnostic');
        return $bytes;
    }

    public static function object(string $stem, string $pair, string $root, string $verb = ''): array {
        $value = json_decode(self::stream($stem, $pair, $root, $verb), true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($value) && !array_is_list($value), 'command did not return one object');
        return $value;
    }

    public static function native(array $source, array $target, array $before, array $seed, string $saved, string $styleFixture): void {
        foreach ([$source, $target, $before] as $record) self::check(($record['format'] ?? null) === 'wprism-qi-native-apply/v1', 'native record format');
        self::check($source['ids'] === $seed['ids'] && array_keys($source['ids']) === ['image', 'first', 'second', 'page'], 'source identity census disagrees with seed');
        self::check(array_keys($target['ids']) === array_keys($source['ids']) && count(array_unique($target['ids'])) === 4
            && min($target['ids']) > max(array_column($source['posts'], 'ID')), 'target identities did not all diverge');
        self::check($source['home'] === $seed['home'] && $source['home'] !== $target['home'] && $before['home'] === $target['home'], 'independent native home bindings');
        foreach ($source['uuids'] as $uuid) self::check(is_string($uuid)
            && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid) === 1, 'source durable identity is absent');
        self::check(array_keys($source['uuids']) === array_keys($source['ids']) && count(array_unique($source['uuids'])) === 4
            && $target['uuids'] === $source['uuids'], 'durable identities changed');
        self::check($before['ids'] === [] && $before['uuids'] === [] && $before['featured'] === [] && $before['attachment'] === null
            && $before['page_body'] === null && $before['uploads'] === [] && count($before['posts']) === 8, 'target was not empty apart from its eight trash witnesses');
        $targetRows = array_column($target['posts'], null, 'ID');
        self::check(count($target['posts']) === 12 && count($targetRows) === 12, 'Apply did not create exactly four physical posts');
        foreach ($before['posts'] as $row) self::check($row['post_status'] === 'trash' && ($targetRows[$row['ID']] ?? null) === $row, 'target-local trash row changed');
        foreach ([$source, $target] as $record) {
            $rows = array_column($record['posts'], null, 'ID');
            foreach (['image' => ['attachment', 'qi-conformance-image'], 'first' => ['post', 'qi-first-source-post'],
                'second' => ['post', 'qi-second-source-post'], 'page' => ['page', 'qi-native-corpus']] as $role => [$type, $slug]) {
                $row = $rows[$record['ids'][$role]] ?? [];
                self::check(($row['post_type'] ?? null) === $type && ($row['post_name'] ?? null) === $slug
                    && ($row['post_status'] ?? null) === ($role === 'image' ? 'inherit' : 'publish'), 'native role does not name its physical row');
            }
            self::check($rows[$record['ids']['page']]['post_content'] === $record['page_body'], 'page body disagrees with complete native row');
            self::check($record['featured'] === ['first' => $record['ids']['image'], 'second' => $record['ids']['image']], 'native query posts lost their featured attachment');
            $imageUrl = str_replace($source['home'], $record['home'], $seed['image_url']);
            $body = $record === $source ? QiConformanceCorpus::body($saved, $record['ids'], $record['home'], $imageUrl)
                : QiConformanceCorpus::applied_body($saved, $record['ids'], $record['home'], $imageUrl);
            self::check($record['page_body'] === $body, 'complete native body differs from independent corpus expectation');
            $styles = WPrism\PhpContainerValue::restore(WPrism\PhpContainerValue::capture_raw($record['styles'], 'native Qi styles'), 'native Qi styles');
            $expected = QiConformanceCorpus::global_styles(QiConformanceCorpus::styles($styleFixture, $body, $record['ids'], $record['home'], $imageUrl), $record['ids']['page']);
            self::check(serialize($styles) === serialize($expected), 'complete native style values, roots, order, types or bindings changed');
        }
        $options = $before['options'];
        self::check(array_column($options, 'option_name') === ['qi_blocks_cropped_images', 'qi_blocks_custom_templates_flag', 'qi_blocks_global_styles']
            && $options[1]['option_value'] === 'target-local-qi-probe', 'target runtime witnesses are absent');
        $options[2]['option_value'] = $target['styles'];
        self::check($target['options'] === $options, 'complete Qi option census changed outside the authored style value');
        self::media($source, $target, $seed);
    }

    public static function media(array $source, array $target, array $seed): void {
        $attachment = $source['attachment'];
        self::check(is_array($attachment) && $attachment['id'] === $source['ids']['image'] && $attachment['url'] === $seed['image_url'], 'source attachment observation is incomplete');
        $expected = $attachment;
        $expected['id'] = $target['ids']['image'];
        $expected['url'] = str_replace($source['home'], $target['home'], $attachment['url']);
        self::check($target['attachment'] === $expected, 'native attachment URL, metadata or file changed');
        $meta = $attachment['metadata'];
        self::check($meta['file'] === $attachment['file'] && $meta['width'] === 1200 && $meta['height'] === 800 && count($meta['sizes']) === 7, 'source native metadata premise changed');
        $directory = dirname($attachment['file']);
        $selected = [$attachment['file'] => ['width' => $meta['width'], 'height' => $meta['height'], 'mime' => 'image/png']];
        foreach ($meta['sizes'] as $size) $selected[$directory . '/' . $size['file']] = ['width' => $size['width'], 'height' => $size['height'], 'mime' => $size['mime-type']];
        $unselected = $directory . '/' . pathinfo($attachment['file'], PATHINFO_FILENAME) . '-333x211.png';
        self::check(isset($source['uploads'][$unselected]) && !isset($selected[$unselected]) && !isset($target['uploads'][$unselected]), 'unselected source-only crop was lost or copied');
        self::check($source['uploads'][$unselected]['width'] === 333 && $source['uploads'][$unselected]['height'] === 211, 'native crop witness dimensions changed');
        $sourceFiles = $source['uploads'];
        unset($sourceFiles[$unselected]);
        self::check($target['uploads'] === $sourceFiles && count($sourceFiles) === 8, 'complete target upload bytes or roster changed');
        ksort($selected, SORT_STRING);
        self::check(array_keys($selected) === array_keys($sourceFiles), 'upload files and native metadata do not close');
        foreach ($selected as $path => $dimensions) {
            $file = $sourceFiles[$path];
            self::check(preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) === 1 && is_int($file['bytes']) && $file['bytes'] > 0
                && ['width' => $file['width'], 'height' => $file['height'], 'mime' => $file['mime']] === $dimensions, 'native image bytes or dimensions are incomplete');
        }
        foreach ([$source, $target] as $record) self::check(!str_contains($record['page_body'] . $record['styles'], basename($unselected)), 'crop fixture became a saved selection; it needs its own native recipe evidence');
    }

    public static function css(string $sourceHtml, string $targetHtml, array $source, array $target): void {
        $css = [];
        foreach (['source' => [$sourceHtml, $source], 'target' => [$targetHtml, $target]] as $side => [$html, $record]) {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new DOMDocument();
                self::check($dom->loadHTML($html), 'frontend HTML is absent');
                $nodes = (new DOMXPath($dom))->query('//*[@id="qi-blocks-main-inline-css"]');
                self::check($nodes->length === 1 && $nodes->item(0)->tagName === 'style', 'frontend does not emit exactly one Qi stylesheet');
                $css[$side] = $nodes->item(0)->textContent;
                preg_match_all('/body\[class\*="-([0-9]+)"\]/', $css[$side], $matches);
                self::check(count($matches[1]) === 204 && array_values(array_unique($matches[1])) === [(string) $record['ids']['page']], 'emitted stylesheet lost its complete native page frames');
                $bodies = $dom->getElementsByTagName('body');
                self::check($bodies->length === 1 && in_array('page-id-' . $record['ids']['page'], preg_split('/\s+/', $bodies->item(0)->getAttribute('class')), true), 'frontend body does not match its style owner');
                self::check(!str_contains($html, 'tmp-qi-image-333x211.png'), 'unselected crop appeared in native frontend');
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $rebound = str_replace($target['home'], $source['home'], $css['target']);
        $rebound = str_replace('body[class*="-' . $target['ids']['page'] . '"]', 'body[class*="-' . $source['ids']['page'] . '"]', $rebound);
        self::check($rebound === $css['source'], 'complete frontend stylesheet changed beyond its independently proven home and page bindings');
    }

    /** Complete native content-only postimage preservation, independent of declarations. */
    public static function content_native(array $source, array $target, array $before, array $seed, string $saved): void {
        foreach ([$source, $target, $before] as $record) self::check(($record['format'] ?? null) === 'wprism-qi-native-apply/v1', 'native content record format');
        self::check($source['home'] === $seed['home'] && $target['home'] === $before['home']
            && $source['home'] !== $target['home'], 'native content home bindings changed');
        self::check($source['ids'] === $seed['ids'] && $target['ids'] === $before['ids']
            && $source['uuids'] === $target['uuids'] && $target['uuids'] === $before['uuids'], 'content update changed native or durable identities');
        foreach ([$source, $target] as $native) {
            $image = str_replace($seed['home'], $native['home'], $seed['image_url']);
            $expected = $native === $source ? QiConformanceCorpus::body($saved, $native['ids'], $native['home'], $image)
                : QiConformanceCorpus::applied_body($saved, $native['ids'], $native['home'], $image);
            self::check($native['page_body'] === $expected, 'complete content body differs from independent native fixture expectation');
            $rows = array_column($native['posts'], null, 'ID');
            self::check(($rows[$native['ids']['page']]['post_content'] ?? null) === $native['page_body'], 'content body differs from its complete stored native row');
        }
        foreach (['home', 'options', 'styles', 'featured', 'attachment', 'uploads'] as $field) {
            self::check($target[$field] === $before[$field], 'content-only update changed complete native ' . $field);
        }
        $posts = array_column($target['posts'], null, 'ID');
        self::check(count($posts) === count($before['posts']), 'content-only update created or deleted native posts');
        foreach ($before['posts'] as $post) if ((int) $post['ID'] !== $before['ids']['page']) {
            self::check(($posts[$post['ID']] ?? null) === $post, 'content-only update changed an unrelated complete native row');
        }
    }

    public static function content_update(array $apply, array $repeat): void {
        foreach ([$apply, $repeat] as $result) QiNativeApplyEvidence::check($result['warnings'] === [] && $result['canary'] === 'clean'
            && $result['drift'] === [] && $result['actions'] === [] && $result['verification'] === [
                'verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 7, 'deletions' => 0, 'skipped_user_meta' => 0,
            ], 'content Apply or repeat did not pass the actual product verifier');
        QiNativeApplyEvidence::check($apply['applied'] === 1 && $repeat['applied'] === 0, 'content update must write one content entity, then zero on repeat');
    }

    public static function apply_pair(array $apply, array $repeat): void {
        foreach ([$apply, $repeat] as $result) self::check($result['canary'] === 'clean' && $result['drift'] === [] && $result['actions'] === []
            && $result['verification'] === ['verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 7, 'deletions' => 0, 'skipped_user_meta' => 0],
            'actual product verification did not pass');
        self::check($apply['applied'] === 7 && $repeat['applied'] === 0 && $repeat['warnings'] === [], 'Apply or zero-write repeat differs');
        self::check(count($apply['warnings']) === 1 && str_starts_with($apply['warnings'][0], 'adopted env term 1 as '),
            'initial Apply lost its exact default-category adoption boundary');
    }

    public static function capture_result(array $capture): void {
        self::check($capture['counts'] === ['post' => 4, 'term' => 1, 'menu' => 0, 'sidebar' => 1, 'options' => 1, 'deletion' => 0]
            && $capture['media'] === 1 && $capture['notes'] === [] && $capture['warnings'] === [] && $capture['initial_code_baseline'] === null,
            'capture omitted content, media or diagnostics');
    }

    public static function product(array $plan, array $apply, array $repeat, array $captures, array $source): void {
        $created = array_column($plan['create'], 'uuid');
        $expected = array_values($source['uuids']);
        sort($created, SORT_STRING);
        sort($expected, SORT_STRING);
        self::check($created === $expected && count($plan['update']) === 2 && count($plan['adopt']) === 1, 'native plan changed authored scope');
        foreach (['warnings', 'provider_problems', 'drift', 'conflict', 'collision', 'delete', 'delete_conflict', 'deleted', 'code_mismatch',
            'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta', 'selected_actions', 'regen_pending', 'regen_context', 'env_missing'] as $field) {
            self::check($plan[$field] === [], 'native plan has unexpected ' . $field);
        }
        self::check(array_column($plan['adapter_dispositions'], 'code') === ['authored_state_not_certified'], 'experimental boundary changed');
        $adopt = $plan['adopt'][0];
        self::check($adopt['type'] === 'term' && $adopt['env_id'] === 1 && $adopt['title'] === 'Uncategorized', 'unexpected native adoption');
        self::check($apply['warnings'] === ['adopted env term 1 as ' . $adopt['uuid'] . ' (' . $adopt['path'] . ')'], 'Apply emitted an unproved diagnostic');
        self::apply_pair($apply, $repeat);
        self::check(count($captures) === 5, 'complete capture stages are absent');
        foreach ($captures as $capture) self::capture_result($capture);
    }
}

if (($argv[1] ?? null) === '--stream') {
    QiNativeApplyEvidence::stream($argv[2], $argv[3], $argv[4], $argv[5]);
}
if (($argv[1] ?? null) === '--admit-roundtrip-native') {
    if ($argc !== 8) throw new RuntimeException('roundtrip native admission requires source, target, preimage, seed, body and style fixtures');
    $read = static fn(string $path): array => json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    QiNativeApplyEvidence::native($read($argv[2]), $read($argv[3]), $read($argv[4]), $read($argv[5]),
        file_get_contents($argv[6]), file_get_contents($argv[7]));
    echo json_encode(['format' => 'wprism-qi-native-roundtrip-admission/v1', 'result' => 'pass'], JSON_THROW_ON_ERROR), "\n";
}
if (($argv[1] ?? null) === '--admit-roundtrip-fixed-point') {
    if ($argc !== 9) throw new RuntimeException('roundtrip fixed-point admission requires Apply, repeat, source/target HTML and three native records');
    $read = static fn(string $path): array => json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $source = $read($argv[6]);
    $target = $read($argv[7]);
    QiNativeApplyEvidence::apply_pair($read($argv[2]), $read($argv[3]));
    QiNativeApplyEvidence::check($target === $read($argv[8]), 'repeat Apply or frontend consumption changed complete target native state');
    QiNativeApplyEvidence::css(file_get_contents($argv[4]), file_get_contents($argv[5]), $source, $target);
    echo json_encode(['format' => 'wprism-qi-native-roundtrip-fixed-point/v1', 'result' => 'pass'], JSON_THROW_ON_ERROR), "\n";
}
if (($argv[1] ?? null) === '--admit') {
    $root = dirname(__DIR__, 4);
    $capsule = dirname(__DIR__, 2);
    require_once $root . '/sandbox/tests/lib/agent_version.php';
    require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $sink = $argv[2];
    $pair = $argv[3];
    $read = static fn(string $name, string $verb = ''): array => QiNativeApplyEvidence::object($sink . '/' . $name, $pair, $root, $verb);
    $source = $read('source1');
    $target = $read('target2');
    QiNativeApplyEvidence::native($source, $target, $read('before2'), $read('seed1'), file_get_contents($capsule . '/fixtures/native-blocks.html'), file_get_contents($capsule . '/fixtures/native-options.json'));
    QiNativeApplyEvidence::check($target === $read('stable2') && $target === $read('target-http-after') && $source === $read('source-http-after'), 'repeat Apply or frontend read changed complete native state');
    QiNativeApplyEvidence::check($read('styles1') === ['status' => 'success', 'message' => 'Options are saved', 'data' => null, 'redirect' => ''], 'native Qi style writer did not succeed');
    $captures = [];
    foreach (['capture1', 'recapture2', 'source-repeat1', 'render-recapture2', 'render-repeat1'] as $name) $captures[] = $read($name, 'capture');
    QiNativeApplyEvidence::product($read('plan2', 'plan'), $read('apply2', 'apply'), $read('repeat2', 'apply'), $captures, $source);
    $library = WPrism\AdapterLibrary::fromSourceTree($root);
    $repositories = [];
    foreach (['source', 'target-input', 'target', 'source-repeat', 'render-source', 'render-target'] as $side) {
        $repo = $sink . '/' . $side;
        $policy = WPrism\Policy::load($repo, adapterLibrary: $library);
        $repositories[$side] = WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
        QiNativeApplyEvidence::check($policy->code_config() === null && $repositories[$side]->code_descriptor() === null, 'native workflow declared managed code');
        if ($side !== 'source') WPrismTest\RepositoryConvergence::assertSame($repositories['source'], $repositories[$side]);
    }
    QiNativeApplyEvidence::css(QiNativeApplyEvidence::stream($sink . '/source-http', $pair, $root), QiNativeApplyEvidence::stream($sink . '/target-http', $pair, $root), $source, $target);
    echo json_encode(['format' => 'wprism-qi-native-apply-admission/v1', 'result' => 'pass', 'source_ids' => $source['ids'], 'target_ids' => $target['ids'],
        'native_block_types' => 47, 'native_block_instances' => 50, 'saved_selector_frames' => 84, 'emitted_selector_frames' => 204,
        'compiled_entities' => 7, 'preserved_target_trash_rows' => 8, 'source_uploads' => 9, 'target_uploads' => 8,
        'scope' => 'Content-only Apply, repeat, complete native body/options/media and HTTP CSS. No browser, selected custom crop, lifecycle, host, version or combination qualification.'], JSON_THROW_ON_ERROR), "\n";
}
