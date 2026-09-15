<?php
declare(strict_types=1);

$repository = dirname(__DIR__, 3);
require_once $repository . '/adapter-packages/qi-blocks/fixtures/native-apply/evidence.php';

/** Cross-adapter facts supplement the harness's exact canonical-tree diff. */
final class QiVisualPortfolioEvidence
{
    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException('Qi + Visual Portfolio evidence: ' . $message);
        }
    }

    private static function read(string $path): array
    {
        self::check(is_file($path) && !is_link($path), 'input is not an ordinary file');
        $size = filesize($path);
        self::check(is_int($size) && $size > 0 && $size <= 32 * 1024 * 1024, 'input exceeds the evidence boundary');
        $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::check(is_array($value) && !array_is_list($value), 'input is not one JSON object');
        return $value;
    }

    private static function rows(array $record): array
    {
        $rows = array_column($record['posts'] ?? [], null, 'ID');
        self::check(count($rows) === count($record['posts'] ?? []), 'post census repeats a physical identity');
        return $rows;
    }

    private static function qiUploads(array $record): array
    {
        $files = array_filter(
            $record['uploads'] ?? [],
            static fn(string $path): bool => str_starts_with(basename($path), 'tmp-qi-image'),
            ARRAY_FILTER_USE_KEY
        );
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function verifiedApply(array $result, string $phase): void
    {
        self::check(($result['canary'] ?? null) === 'clean'
            && ($result['drift'] ?? null) === []
            && ($result['verification']['verifier'] ?? null) === 'canonical-recapture/v1'
            && ($result['verification']['result'] ?? null) === 'pass'
            && ($result['verification']['deletions'] ?? null) === 0
            && ($result['verification']['skipped_user_meta'] ?? null) === 0,
            $phase . ' did not pass the shared product verifier');
    }

    private static function initialReceipt(array $result): void
    {
        self::verifiedApply($result, 'initial combined Apply');
        self::check(($result['plan'] ?? null) === [
            'create' => 22,
            'update' => 2,
            'unchanged' => 0,
            'drift' => 0,
            'conflict' => 0,
            'adopt' => 2,
            'collision' => 0,
            'delete' => 0,
            'delete_conflict' => 0,
            'deleted' => 0,
            'code_mismatch' => 0,
            'code_drift' => 0,
            'incomplete_apply' => 0,
            'incomplete_lifecycle' => 0,
            'missing_user' => 0,
            'skipped_user_meta' => 0,
            'uploads_inventory' => 3,
            'effects_inventory' => 9,
            'lifecycle_effects_inventory' => 5,
            'selected_actions' => 1,
            'adapter_dispositions' => 3,
            'regen_pending' => 0,
            'regen_context' => 0,
            'env_missing' => 5,
        ] && ($result['applied'] ?? null) === 26
            && ($result['verification']['live_entities'] ?? null) === 26,
            'initial receipt does not account for the complete combined workload');

        $warnings = $result['warnings'] ?? null;
        self::check(is_array($warnings) && count($warnings) === 5
            && is_string($warnings[0] ?? null)
            && preg_match('~^adopted env post 1 as ([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}) \\(posts/page/\\1--portfolio\\.md\\)$~D', $warnings[0]) === 1
            && is_string($warnings[1] ?? null)
            && preg_match('~^adopted env term 1 as ([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}) \\(terms/category/\\1--uncategorized\\.json\\)$~D', $warnings[1]) === 1
            && ($warnings[2] ?? null) === "option vp_images: no live value to sub-key-merge into — creating it containing ONLY the declared sub-keys (its owning plugin's own defaults are absent; expected if that plugin has not been deployed/activated on this target yet)"
            && ($warnings[3] ?? null) === "option vp_popup_gallery: no live value to sub-key-merge into — creating it containing ONLY the declared sub-keys (its owning plugin's own defaults are absent; expected if that plugin has not been deployed/activated on this target yet)"
            && is_string($warnings[4] ?? null),
            'initial receipt lost an adoption or option-creation boundary');

        $actions = $result['actions'] ?? null;
        $action = is_array($actions) && count($actions) === 1 ? $actions[0] : null;
        self::check(is_array($action)
            && ($action['manifest'] ?? null) === 'visual-portfolio'
            && ($action['source'] ?? null) === 'provider:visual-portfolio-settings/reconcile_settings'
            && ($action['kind'] ?? null) === 'provider'
            && ($action['provider_version'] ?? null) === '1.0.0'
            && (is_int($action['duration_seconds'] ?? null) || is_float($action['duration_seconds'] ?? null))
            && is_finite((float) $action['duration_seconds']) && $action['duration_seconds'] > 0
            && ($action['verified'] ?? null) === true,
            'initial receipt lost the verified Visual Portfolio provider action');
        self::check($warnings[4] === 'provider capability fired: visual-portfolio-settings@1.0.0 reconcile_settings ('
            . $action['duration_seconds'] . 's, verified)',
            'provider warning disagrees with its typed action receipt');
        $before = $action['before'] ?? null;
        $after = $action['after'] ?? null;
        $hashKeys = ['derived_sha256', 'files_sha256', 'inputs_sha256', 'remainder_sha256'];
        self::check(is_array($before) && is_array($after)
            && array_keys($before) === $hashKeys && array_keys($after) === $hashKeys,
            'provider action does not carry its complete before/after witness');
        foreach ($hashKeys as $key) {
            self::check(is_string($before[$key]) && preg_match('/^[a-f0-9]{64}$/D', $before[$key]) === 1
                && is_string($after[$key]) && preg_match('/^[a-f0-9]{64}$/D', $after[$key]) === 1,
                'provider action carries an invalid state hash');
        }
        self::check($before['derived_sha256'] !== $after['derived_sha256']
            && $before['files_sha256'] === $after['files_sha256']
            && $before['inputs_sha256'] === $after['inputs_sha256']
            && $before['remainder_sha256'] === $after['remainder_sha256'],
            'provider action did not isolate its derived storage change');
    }

    private static function repeatReceipt(array $result): void
    {
        self::verifiedApply($result, 'repeated combined Apply');
        self::check(($result['plan'] ?? null) === [
            'create' => 0,
            'update' => 0,
            'unchanged' => 26,
            'drift' => 0,
            'conflict' => 0,
            'adopt' => 0,
            'collision' => 0,
            'delete' => 0,
            'delete_conflict' => 0,
            'deleted' => 0,
            'code_mismatch' => 0,
            'code_drift' => 0,
            'incomplete_apply' => 0,
            'incomplete_lifecycle' => 0,
            'missing_user' => 0,
            'skipped_user_meta' => 0,
            'uploads_inventory' => 3,
            'effects_inventory' => 1,
            'lifecycle_effects_inventory' => 5,
            'selected_actions' => 0,
            'adapter_dispositions' => 3,
            'regen_pending' => 0,
            'regen_context' => 0,
            'env_missing' => 3,
        ] && ($result['applied'] ?? null) === 0
            && ($result['warnings'] ?? null) === []
            && ($result['actions'] ?? null) === []
            && ($result['verification']['live_entities'] ?? null) === 26,
            'repeated receipt is not the complete zero-write fixed point');
    }

    public static function native(
        array $source,
        array $target,
        array $before,
        array $seed,
        string $savedBlocks,
        string $styleFixture,
        array $apply
    ): void {
        foreach ([$source, $target, $before] as $record) {
            self::check(($record['format'] ?? null) === 'wprism-qi-native-apply/v1', 'native observation format changed');
        }
        self::check(($source['ids'] ?? null) === ($seed['ids'] ?? null)
            && array_keys($source['ids']) === ['image', 'first', 'second', 'page'], 'source Qi roles disagree with native authoring');
        self::check(array_keys($target['ids'] ?? []) === array_keys($source['ids'])
            && count(array_unique($target['ids'])) === 4, 'target Qi roles are incomplete or collide');
        foreach ($source['ids'] as $role => $id) {
            self::check($target['ids'][$role] !== $id, 'Qi role retained a source-local identity');
        }
        self::check(($source['uuids'] ?? null) === ($target['uuids'] ?? null)
            && count(array_unique($source['uuids'] ?? [])) === 4, 'Qi durable identities did not survive the combination');
        foreach ($source['uuids'] as $uuid) {
            self::check(is_string($uuid)
                && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid) === 1,
                'Qi role lacks a durable identity');
        }
        self::check(is_string($source['home'] ?? null) && is_string($target['home'] ?? null)
            && $source['home'] !== $target['home'] && $before['home'] === $target['home'],
            'source and target origins are not independent');

        foreach ([$source, $target] as $record) {
            $rows = self::rows($record);
            foreach ([
                'image' => ['attachment', 'qi-conformance-image'],
                'first' => ['post', 'qi-first-source-post'],
                'second' => ['post', 'qi-second-source-post'],
                'page' => ['page', 'qi-native-corpus'],
            ] as $role => [$type, $slug]) {
                $row = $rows[$record['ids'][$role]] ?? [];
                self::check(($row['post_type'] ?? null) === $type && ($row['post_name'] ?? null) === $slug
                    && ($row['post_status'] ?? null) === ($role === 'image' ? 'inherit' : 'publish'),
                    'Qi role does not resolve to its native row');
            }
            self::check($rows[$record['ids']['page']]['post_content'] === $record['page_body'],
                'Qi page body disagrees with its native row');
            self::check($record['featured'] === [
                'first' => $record['ids']['image'],
                'second' => $record['ids']['image'],
            ], 'Qi featured-image roles changed under the combination');
            $imageUrl = str_replace($source['home'], $record['home'], $seed['image_url']);
            $expectedBody = $record === $source
                ? QiConformanceCorpus::body($savedBlocks, $record['ids'], $record['home'], $imageUrl)
                : QiConformanceCorpus::applied_body($savedBlocks, $record['ids'], $record['home'], $imageUrl);
            self::check($record['page_body'] === $expectedBody, 'Qi native body changed under Visual Portfolio');
            $styles = WPrism\PhpContainerValue::restore(
                WPrism\PhpContainerValue::capture_raw($record['styles'], 'combined Qi styles'),
                'combined Qi styles'
            );
            $expectedStyles = QiConformanceCorpus::global_styles(
                QiConformanceCorpus::styles($styleFixture, $expectedBody, $record['ids'], $record['home'], $imageUrl),
                $record['ids']['page']
            );
            self::check(serialize($styles) === serialize($expectedStyles),
                'Qi style roots, order, types or bindings changed under Visual Portfolio');
        }

        self::check(($before['ids'] ?? null) === [] && ($before['uuids'] ?? null) === []
            && ($before['featured'] ?? null) === [] && ($before['attachment'] ?? null) === null
            && ($before['page_body'] ?? null) === null && ($before['uploads'] ?? null) === [],
            'target preimage already contains a managed Qi role');
        $beforeRows = self::rows($before);
        $targetRows = self::rows($target);
        self::check(count($beforeRows) === 42 && count($targetRows) === 56,
            'combined physical post census changed');
        $padding = ['Unmanaged Qi target ' => 0, 'Target identity padding ' => 0];
        foreach ($beforeRows as $id => $row) {
            $title = (string) ($row['post_title'] ?? '');
            if (str_starts_with($title, 'Unmanaged Qi target ')) {
                ++$padding['Unmanaged Qi target '];
            } elseif (str_starts_with($title, 'Target identity padding ')) {
                ++$padding['Target identity padding '];
            } else {
                continue;
            }
            self::check(($row['post_status'] ?? null) === 'trash' && ($targetRows[$id] ?? null) === $row,
                'target-local identity witness changed during combined Apply');
        }
        self::check($padding === ['Unmanaged Qi target ' => 8, 'Target identity padding ' => 32],
            'target-local witness families are incomplete');
        self::check(($beforeRows[1]['post_type'] ?? null) === 'page'
            && ($beforeRows[1]['post_name'] ?? null) === 'portfolio'
            && ($beforeRows[1]['post_status'] ?? null) === 'publish'
            && ($beforeRows[2]['post_type'] ?? null) === 'revision'
            && ($beforeRows[2]['post_parent'] ?? null) === '1'
            && ($targetRows[2] ?? null) === $beforeRows[2],
            'Visual Portfolio target-native archive premise changed');
        $adoptedArchive = $beforeRows[1];
        foreach (['post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt'] as $field) {
            $adoptedArchive[$field] = $targetRows[1][$field] ?? null;
        }
        self::check(($targetRows[1] ?? null) === $adoptedArchive,
            'archive adoption changed fields outside its canonical timestamps');
        $added = array_diff_key($targetRows, $beforeRows);
        $addedTypes = [];
        foreach ($added as $row) {
            $type = $row['post_type'] ?? null;
            self::check(is_string($type) && $type !== '', 'new physical row lacks a post type');
            $addedTypes[$type] = ($addedTypes[$type] ?? 0) + 1;
        }
        ksort($addedTypes, SORT_STRING);
        self::check($addedTypes === [
            'attachment' => 3,
            'page' => 3,
            'portfolio' => 4,
            'post' => 2,
            'revision' => 2,
        ], 'combined postimage does not contain its exact managed rows and editor revisions');

        $beforeOptions = array_column($before['options'] ?? [], null, 'option_name');
        $targetOptions = array_column($target['options'] ?? [], null, 'option_name');
        self::check(array_keys($beforeOptions) === [
            'qi_blocks_cropped_images',
            'qi_blocks_custom_templates_flag',
            'qi_blocks_global_styles',
        ] && ($beforeOptions['qi_blocks_custom_templates_flag']['option_value'] ?? null) === 'target-local-qi-probe',
            'Qi target-local option premise changed');
        $beforeOptions['qi_blocks_global_styles']['option_value'] = $target['styles'];
        self::check($targetOptions === $beforeOptions, 'combined Apply changed a target-local Qi option');

        $attachment = $source['attachment'] ?? null;
        self::check(is_array($attachment) && $attachment['id'] === $source['ids']['image']
            && $attachment['url'] === $seed['image_url'], 'Qi source attachment is incomplete');
        $expectedAttachment = $attachment;
        $expectedAttachment['id'] = $target['ids']['image'];
        $expectedAttachment['url'] = str_replace($source['home'], $target['home'], $attachment['url']);
        self::check(($target['attachment'] ?? null) === $expectedAttachment,
            'Qi attachment metadata or original changed under Visual Portfolio');
        $sourceUploads = self::qiUploads($source);
        $targetUploads = self::qiUploads($target);
        $unselected = dirname($attachment['file']) . '/' . pathinfo($attachment['file'], PATHINFO_FILENAME) . '-333x211.png';
        self::check(count($sourceUploads) === 11 && isset($sourceUploads[$unselected])
            && count($targetUploads) === 10 && !isset($targetUploads[$unselected])
            && isset($targetUploads[dirname($attachment['file']) . '/tmp-qi-image-500x333.png'])
            && isset($targetUploads[dirname($attachment['file']) . '/tmp-qi-image-800x533.png']),
            'Qi selection or Visual Portfolio derivative boundary changed in the combination');
        unset($sourceUploads[$unselected]);
        self::check($targetUploads === $sourceUploads, 'Qi selected media bytes changed under Visual Portfolio');

        self::initialReceipt($apply);
    }

    public static function fixedPoint(
        array $apply,
        array $repeat,
        string $sourceHtml,
        string $targetHtml,
        array $source,
        array $target,
        array $stable,
        array $visualPortfolioBefore,
        array $visualPortfolioStable
    ): void {
        self::initialReceipt($apply);
        self::repeatReceipt($repeat);
        self::check($target === $stable, 'repeat Apply or HTTP consumption changed complete target state');
        self::check($visualPortfolioBefore === $visualPortfolioStable,
            'repeat Apply changed complete Visual Portfolio native state');
        QiNativeApplyEvidence::css($sourceHtml, $targetHtml, $source, $target);
    }

    public static function run(array $arguments): void
    {
        $mode = $arguments[1] ?? null;
        if ($mode === '--native' && count($arguments) === 9) {
            self::native(
                self::read($arguments[2]),
                self::read($arguments[3]),
                self::read($arguments[4]),
                self::read($arguments[5]),
                (string) file_get_contents($arguments[6]),
                (string) file_get_contents($arguments[7]),
                self::read($arguments[8])
            );
            echo json_encode(['format' => 'wprism-qi-vp-native-combination/v1', 'result' => 'pass'], JSON_THROW_ON_ERROR), "\n";
            return;
        }
        if ($mode === '--fixed-point' && count($arguments) === 11) {
            self::fixedPoint(
                self::read($arguments[2]),
                self::read($arguments[3]),
                (string) file_get_contents($arguments[4]),
                (string) file_get_contents($arguments[5]),
                self::read($arguments[6]),
                self::read($arguments[7]),
                self::read($arguments[8]),
                self::read($arguments[9]),
                self::read($arguments[10])
            );
            echo json_encode(['format' => 'wprism-qi-vp-fixed-point/v1', 'result' => 'pass'], JSON_THROW_ON_ERROR), "\n";
            return;
        }
        throw new RuntimeException('expected one complete native or fixed-point evidence invocation');
    }
}

try {
    QiVisualPortfolioEvidence::run($argv);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
