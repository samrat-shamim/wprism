<?php
/** Exact WooCommerce 11.0.x request-time thumbnail convergence product path. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
duo_test_define_agent_versions();

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/LockingFakeWpdb.php';

$root = dirname(__DIR__, 4);
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Kernel/PlainData.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/adapter-packages/woocommerce/package/runtime/interpreters/woocommerce.php';

use Duo\ApplyFieldMaterializer;
use Duo\Policy;
use Duo\Interpreters\Woocommerce;
use DuoTest\FakeWpdb;
use DuoTest\LockingFakeWpdb;
use DuoTest\WpStore;

/**
 * Attachment state behind the exact WordPress function names crossed by
 * WC_Regenerate_Images. Files are real scratch bytes so a missing-file retry
 * cannot be satisfied by an in-memory flag that production never consults.
 *
 * @var array<int,array<string,mixed>>
 */
$GLOBALS['wooThumbnailAttachments'] = [];

/** @var array<string,mixed>|null */
$GLOBALS['wooThumbnailThemeSupport'] = null;

/** @var array<string,array<string,mixed>> */
$GLOBALS['wooThumbnailRegisteredSizes'] = [];

/** @return array<string,mixed> */
function woo_thumbnail_target_size(): array {
    $themeSupport = $GLOBALS['wooThumbnailThemeSupport'];
    $width = absint(is_array($themeSupport) && array_key_exists('thumbnail_image_width', $themeSupport)
        ? $themeSupport['thumbnail_image_width']
        : get_option('woocommerce_thumbnail_image_width', 300));
    $cropping = get_option('woocommerce_thumbnail_cropping', '1:1');
    if ($cropping === 'uncropped') {
        return ['width' => $width, 'height' => '', 'crop' => 0];
    }
    if ($cropping === 'custom') {
        $ratioWidth = max(1, (float) get_option('woocommerce_thumbnail_cropping_custom_width', '4'));
        $ratioHeight = max(1, (float) get_option('woocommerce_thumbnail_cropping_custom_height', '3'));
    } else {
        $parts = explode(':', (string) $cropping);
        $ratioWidth = max(1, (float) ($parts[0] ?? 1));
        $ratioHeight = max(1, (float) ($parts[count($parts) - 1] ?? 1));
    }
    return [
        'width' => $width,
        'height' => absint(round(($width / $ratioWidth) * $ratioHeight)),
        'crop' => 1,
    ];
}

if (!function_exists('wc_get_image_size')) {
    /** @return array<string,mixed> */
    function wc_get_image_size(string $size): array {
        if ($size === 'woocommerce_thumbnail' || $size === 'thumbnail') {
            $cacheKey = 'size-' . $size;
            $cached = wp_cache_get($cacheKey, 'woocommerce');
            if (is_array($cached)) {
                return $cached;
            }
            $target = woo_thumbnail_target_size();
            wp_cache_set($cacheKey, $target, 'woocommerce');
            return $target;
        }
        return ['width' => 600, 'height' => '', 'crop' => 0];
    }
}

function woo_thumbnail_clear_size_cache(): void {
    wp_cache_delete('size-thumbnail', 'woocommerce');
    wp_cache_delete('size-woocommerce_thumbnail', 'woocommerce');
}

/** @return array<string,mixed> */
function woo_thumbnail_register_image_size(): array {
    woo_thumbnail_clear_size_cache();
    $target = wc_get_image_size('thumbnail');
    $GLOBALS['wooThumbnailRegisteredSizes']['woocommerce_thumbnail'] = $target;
    return $target;
}

if (!function_exists('wp_image_matches_ratio')) {
    function wp_image_matches_ratio(int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): bool {
        return $sourceWidth > 0 && $sourceHeight > 0 && $targetWidth > 0 && $targetHeight > 0
            && $sourceWidth * $targetHeight === $sourceHeight * $targetWidth;
    }
}

/** @return array{0:int,1:int} */
function woo_thumbnail_constrain(int $width, int $height, int $maxWidth, int|string $maxHeight): array {
    if ($width <= 0 || $height <= 0 || $maxWidth <= 0) {
        return [$width, $height];
    }
    $scale = min(1, $maxWidth / $width);
    if (is_int($maxHeight) && $maxHeight > 0) {
        $scale = min($scale, $maxHeight / $height);
    }
    return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
}

/** @return array<string,mixed>|false */
function wp_get_attachment_metadata(int $attachmentId): array|false {
    $metadata = $GLOBALS['wooThumbnailAttachments'][$attachmentId]['metadata'] ?? false;
    return is_array($metadata) ? $metadata : false;
}

function get_attached_file(int $attachmentId): string|false {
    $file = $GLOBALS['wooThumbnailAttachments'][$attachmentId]['path'] ?? false;
    return is_string($file) ? $file : false;
}

function wp_attachment_is_image(int $attachmentId): bool {
    return ($GLOBALS['wooThumbnailAttachments'][$attachmentId]['is_image'] ?? false) === true;
}

/** @return array<string,mixed>|false */
function image_get_intermediate_size(int $attachmentId, string|array $size): array|false {
    $attachment = $GLOBALS['wooThumbnailAttachments'][$attachmentId] ?? null;
    $metadata = $attachment['metadata'] ?? false;
    if (!is_array($attachment) || !is_array($metadata)) {
        return false;
    }
    if (is_string($size)) {
        $row = $metadata['sizes'][$size] ?? false;
        return is_array($row) ? $row : false;
    }
    [$width, $height] = woo_thumbnail_constrain(
        (int) ($metadata['width'] ?? 0),
        (int) ($metadata['height'] ?? 0),
        (int) ($size[0] ?? 0),
        (int) ($size[1] ?? 0)
    );
    if ($width <= 0 || $height <= 0) {
        return false;
    }
    return [
        'file' => (string) ($metadata['file'] ?? ''),
        'width' => $width,
        'height' => $height,
        'intermediate' => false,
        'uncropped' => true,
    ];
}

/** @param array<string,mixed> $data @return array{0:string,1:int,2:int,3:bool} */
function woo_thumbnail_image_tuple(int $attachmentId, array $data): array {
    $attachment = $GLOBALS['wooThumbnailAttachments'][$attachmentId];
    $file = (string) ($data['file'] ?? $attachment['relative_file']);
    return [
        rtrim(WpStore::instance()->uploadBaseUrl, '/') . '/' . ltrim($file, '/'),
        (int) ($data['width'] ?? 0),
        (int) ($data['height'] ?? 0),
        (bool) ($data['intermediate'] ?? true),
    ];
}

/** @return array{0:string,1:int,2:int,3:bool}|false */
function image_downsize(int $attachmentId, string $size): array|false {
    $data = image_get_intermediate_size($attachmentId, $size);
    return is_array($data) ? woo_thumbnail_image_tuple($attachmentId, $data) : false;
}

/** @return array<string,mixed>|WP_Error|false */
function wp_generate_attachment_metadata(int $attachmentId, string $file): array|WP_Error|false {
    $attachment = &$GLOBALS['wooThumbnailAttachments'][$attachmentId];
    // WordPress 7.1 persists this base projection before it asks an image
    // editor to create sub-sizes. An unavailable editor therefore replaces a
    // stale derivative with sizes=[]; the later successful request commits
    // only the requested Woo derivative to that base metadata.
    $baseMetadata = [
        'file' => $attachment['relative_file'],
        'width' => (int) $attachment['full_width'],
        'height' => (int) $attachment['full_height'],
        'filesize' => (int) filesize($file),
        'sizes' => [],
    ];
    wp_update_attachment_metadata($attachmentId, $baseMetadata);
    ++$attachment['editor_attempts'];
    if (($attachment['editor_failures_remaining'] ?? 0) > 0) {
        --$attachment['editor_failures_remaining'];
        // _wp_make_subsizes() returns the already-persisted base projection
        // when wp_get_image_editor() fails; wp_generate_attachment_metadata()
        // then still crosses Woo's metadata filter with that projection.
        return apply_filters('wp_generate_attachment_metadata', $baseMetadata, $attachmentId, 'create');
    }
    $target = $GLOBALS['wooThumbnailRegisteredSizes']['woocommerce_thumbnail'] ?? null;
    if (!is_array($target)) {
        throw new RuntimeException('Woo thumbnail size was not registered at request bootstrap');
    }
    $fullWidth = (int) $attachment['full_width'];
    $fullHeight = (int) $attachment['full_height'];
    if ($target['crop']) {
        if ($fullWidth < $target['width'] || $fullHeight < $target['height']) {
            return false;
        }
        $width = (int) $target['width'];
        $height = (int) $target['height'];
    } else {
        [$width, $height] = woo_thumbnail_constrain($fullWidth, $fullHeight, (int) $target['width'], '');
    }
    $metadata = [
        'file' => $attachment['relative_file'],
        'width' => $fullWidth,
        'height' => $fullHeight,
        'filesize' => (int) filesize($file),
        'sizes' => [
            'woocommerce_thumbnail' => [
                'file' => "thumb-$attachmentId-{$width}x{$height}.jpg",
                'width' => $width,
                'height' => $height,
                'mime-type' => 'image/jpeg',
                'intermediate' => true,
            ],
        ],
    ];
    // WordPress persists each successful sub-size before the outer
    // wp_generate_attachment_metadata filter runs. Woo then adds its
    // uncropped marker to the returned row and commits that distinct row in
    // resizeAndReturn(), so both writes are part of the exact product path.
    wp_update_attachment_metadata($attachmentId, $metadata);
    return apply_filters('wp_generate_attachment_metadata', $metadata, $attachmentId, 'create');
}

/** @param array<string,mixed> $metadata */
function wp_update_attachment_metadata(int $attachmentId, array $metadata): bool {
    $check = apply_filters(
        'update_post_metadata',
        null,
        $attachmentId,
        '_wp_attachment_metadata',
        $metadata,
        ''
    );
    if ($check !== null) {
        return (bool) $check;
    }
    $attachment = &$GLOBALS['wooThumbnailAttachments'][$attachmentId];
    if (($attachment['metadata'] ?? false) === $metadata) {
        return false;
    }
    $attachment['metadata'] = $metadata;
    ++$attachment['metadata_writes'];
    return true;
}

/** @return array{0:string,1:int,2:int,3:bool}|false */
function wp_get_attachment_image_src(int $attachmentId, string|array $size = 'thumbnail', bool $icon = false): array|false {
    $attachment = $GLOBALS['wooThumbnailAttachments'][$attachmentId] ?? null;
    if (!is_array($attachment)) {
        return false;
    }
    $data = is_string($size) ? image_get_intermediate_size($attachmentId, $size) : false;
    if (!is_array($data)) {
        $metadata = $attachment['metadata'] ?? false;
        $data = is_array($metadata)
            ? [
                'file' => $metadata['file'],
                'width' => (int) $metadata['width'],
                'height' => (int) $metadata['height'],
                'intermediate' => false,
                'uncropped' => true,
            ]
            : $attachment['fallback'];
    }
    $data = apply_filters('image_get_intermediate_size', $data, $attachmentId, $size);
    $image = is_array($data)
        ? woo_thumbnail_image_tuple($attachmentId, $data)
        : woo_thumbnail_image_tuple($attachmentId, $attachment['fallback']);
    return apply_filters('wp_get_attachment_image_src', $image, $attachmentId, $size, $icon);
}

if (!function_exists('is_customize_preview')) {
    function is_customize_preview(): bool {
        return false;
    }
}

final class WC_Post_Data {
    public static int $metadataCalls = 0;

    public static function init(): void {
        add_filter('update_post_metadata', [self::class, 'update_post_metadata'], 10, 5);
    }

    public static function update_post_metadata(
        mixed $check,
        int $objectId,
        string $metaKey,
        mixed $metaValue,
        mixed $prevValue
    ): mixed {
        ++self::$metadataCalls;
        return $check;
    }
}

/** Exact request-path projection of the byte-pinned WooCommerce 11.0.0/11.0.1 class. */
final class WC_Regenerate_Images {
    private static string $regenerateSize = '';

    public static function init(): void {
        add_action('image_get_intermediate_size', [self::class, 'filter_image_get_intermediate_size'], 10, 3);
        add_filter('wp_generate_attachment_metadata', [self::class, 'add_uncropped_metadata']);
        add_filter('wp_get_attachment_image_src', [self::class, 'maybe_resize_image'], 10, 4);
    }

    /** @param array<string,mixed>|false $data @return array<string,mixed>|false */
    public static function filter_image_get_intermediate_size(
        array|false $data,
        int $attachmentId,
        string|array $size
    ): array|false {
        if (!is_string($size) || $size !== 'woocommerce_thumbnail' || !is_array($data)
            || !isset($data['width'], $data['height'])) {
            return $data;
        }
        if (!self::imageSizeMatchesSettings($data, $size)) {
            $target = wc_get_image_size($size);
            return image_get_intermediate_size(
                $attachmentId,
                [absint($target['width']), absint($target['height'])]
            );
        }
        return $data;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public static function add_uncropped_metadata(array $metadata): array {
        $target = wc_get_image_size('woocommerce_thumbnail');
        if (isset($metadata['sizes']['woocommerce_thumbnail'])) {
            $metadata['sizes']['woocommerce_thumbnail']['uncropped'] = empty($target['height']);
        }
        return $metadata;
    }

    /** @param array{0:string,1:int,2:int,3:bool}|false $image @return array{0:string,1:int,2:int,3:bool}|false */
    public static function maybe_resize_image(
        array|false $image,
        int $attachmentId,
        string|array $size,
        bool $icon
    ): array|false {
        if (!$image || $size !== 'woocommerce_thumbnail') {
            return $image;
        }
        $target = wc_get_image_size((string) $size);
        $imageWidth = $image[1];
        $imageHeight = $image[2];
        $uncropped = $target['width'] === '' || $target['height'] === '' || !$target['crop'];
        $full = self::fullDimensions($attachmentId);
        $ratioMatch = $uncropped
            ? $full !== [] && wp_image_matches_ratio($imageWidth, $imageHeight, $full['width'], $full['height'])
            : wp_image_matches_ratio($imageWidth, $imageHeight, (int) $target['width'], (int) $target['height']);
        if ($ratioMatch || $full === []) {
            return $image;
        }
        if (($imageWidth === $target['width'] && $full['height'] < $target['height'])
            || ($imageHeight === $target['height'] && $full['width'] < $target['width'])
            || ($full['height'] < $target['height'] && $full['width'] < $target['width'])) {
            return $image;
        }
        return self::resizeAndReturn($attachmentId, $image, (string) $size);
    }

    /** @param array<string,mixed> $image */
    private static function imageSizeMatchesSettings(array $image, string $size): bool {
        $target = wc_get_image_size($size);
        $uncropped = $target['width'] === '' || $target['height'] === '';
        if (!$uncropped) {
            $ratio = wp_image_matches_ratio(
                (int) $image['width'],
                (int) $image['height'],
                (int) $target['width'],
                (int) $target['height']
            );
            if ($ratio && $target['width'] !== $image['width']) {
                return false;
            }
            if ($ratio && $target['height'] && $target['height'] !== $image['height']) {
                return false;
            }
        }
        return !($uncropped && empty($image['uncropped']));
    }

    /** @return array{width:int,height:int}|array{} */
    private static function fullDimensions(int $attachmentId): array {
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata) || !isset($metadata['width'], $metadata['height'])) {
            return [];
        }
        return ['width' => (int) $metadata['width'], 'height' => (int) $metadata['height']];
    }

    /** @param array{0:string,1:int,2:int,3:bool} $image @return array{0:string,1:int,2:int,3:bool} */
    private static function resizeAndReturn(int $attachmentId, array $image, string $size): array {
        if (!wp_attachment_is_image($attachmentId)) {
            return $image;
        }
        $file = get_attached_file($attachmentId);
        if (!is_string($file) || !file_exists($file)) {
            return $image;
        }
        self::$regenerateSize = $size;
        $metadata = wp_get_attachment_metadata($attachmentId);
        $metadata = is_array($metadata) ? $metadata : [];
        $newMetadata = wp_generate_attachment_metadata($attachmentId, $file);
        if (is_wp_error($newMetadata) || empty($newMetadata)) {
            return $image;
        }
        if (isset($newMetadata['sizes'][self::$regenerateSize])) {
            $metadata['sizes'][self::$regenerateSize] = $newMetadata['sizes'][self::$regenerateSize];
            wp_update_attachment_metadata($attachmentId, $metadata);
        }
        $newImage = image_downsize($attachmentId, self::$regenerateSize);
        return $newImage ?: $image;
    }
}

/** Seed one real scratch attachment and optional stale thumbnail metadata. */
function woo_thumbnail_seed_attachment(
    int $id,
    int $fullWidth,
    int $fullHeight,
    ?array $staleSize,
    bool $metadataPresent = true,
    bool $filePresent = true,
    bool $isImage = true,
    int $editorFailures = 0
): void {
    $base = WpStore::instance()->ensureUploadDir();
    $relative = "2030/01/full-$id.jpg";
    $path = "$base/$relative";
    if ($filePresent) {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, "duo-thumbnail-fixture-$id");
    }
    $sizes = [];
    if (is_array($staleSize)) {
        $sizes['woocommerce_thumbnail'] = [
            'file' => "thumb-$id-{$staleSize[0]}x{$staleSize[1]}.jpg",
            'width' => $staleSize[0],
            'height' => $staleSize[1],
            'mime-type' => 'image/jpeg',
            'intermediate' => true,
            'uncropped' => $staleSize[2] ?? false,
        ];
    }
    $GLOBALS['wooThumbnailAttachments'][$id] = [
        'relative_file' => $relative,
        'path' => $path,
        'full_width' => $fullWidth,
        'full_height' => $fullHeight,
        'is_image' => $isImage,
        'metadata' => $metadataPresent
            ? ['file' => $relative, 'width' => $fullWidth, 'height' => $fullHeight, 'sizes' => $sizes]
            : false,
        'fallback' => [
            'file' => $relative,
            'width' => $fullWidth,
            'height' => $fullHeight,
            'intermediate' => false,
            'uncropped' => true,
        ],
        'editor_failures_remaining' => $editorFailures,
        'editor_attempts' => 0,
        'metadata_writes' => 0,
    ];
}

function woo_thumbnail_restore_file(int $attachmentId): void {
    $path = (string) $GLOBALS['wooThumbnailAttachments'][$attachmentId]['path'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents($path, "duo-thumbnail-restored-$attachmentId");
}

/** @return list<array<string,mixed>> */
function woo_thumbnail_repository_diagnostics(Woocommerce $interpreter, array $records): array {
    return $interpreter->repository_diagnostics([[
        'type' => 'options',
        'path' => 'state/options/woocommerce.json',
        'data' => ['records' => $records],
    ]]);
}

/** @return array{0:string,1:int,2:int,3:bool}|false */
function woo_thumbnail_frontend(int $attachmentId): array|false {
    return wp_get_attachment_image_src($attachmentId, 'woocommerce_thumbnail');
}

/** @return array{0:string,1:int,2:int,3:bool}|false */
function woo_thumbnail_rest(int $attachmentId): array|false {
    return wp_get_attachment_image_src($attachmentId, 'woocommerce_thumbnail');
}

/** @return array{0:string,1:int,2:int,3:bool}|false */
function woo_thumbnail_store_api(int $attachmentId): array|false {
    return wp_get_attachment_image_src($attachmentId, 'woocommerce_thumbnail');
}

$settingsInventory = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/woocommerce-core-11.0-settings.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$convergence = $settingsInventory['closed_records']['derived_convergence_options']['thumbnail_images'] ?? null;
duo_check(is_array($convergence), 'the exact 11.0.x settings union declares thumbnail request convergence');
duo_check_same(
    ['woocommerce_thumbnail_cropping', 'woocommerce_thumbnail_cropping_custom_width',
        'woocommerce_thumbnail_cropping_custom_height', 'woocommerce_thumbnail_image_width'],
    $convergence['authored_options'] ?? null,
    'the product path owns all four merchant thumbnail controls'
);
duo_check_same(
    ['woocommerce_maybe_regenerate_images_hash', 'wp_<blog>_wc_regenerate_images_batch_*'],
    $convergence['background_state'] ?? null,
    'the Customizer hash and background queue remain runtime acceleration'
);
duo_check_same(
    'target_environment_owned',
    $convergence['external_processors'] ?? null,
    'external image processors remain an explicit target-environment boundary'
);

$store = WpStore::reset()->seedOptions([
    'woocommerce_thumbnail_cropping' => '1:1',
    'woocommerce_thumbnail_cropping_custom_width' => '4',
    'woocommerce_thumbnail_cropping_custom_height' => '3',
    'woocommerce_thumbnail_image_width' => '300',
    'woocommerce_maybe_regenerate_images_hash' => 'target-runtime-hash',
    'wp_1_wc_regenerate_images_batch_91' => ['attachment_id' => 91],
]);
$innerWpdb = new FakeWpdb();
$wpdb = new LockingFakeWpdb($innerWpdb);
$GLOBALS['wpdb'] = $wpdb;
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'woocommerce_thumbnail_cropping', 'option_value' => '1:1', 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'woocommerce_thumbnail_cropping_custom_width', 'option_value' => '4', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'woocommerce_thumbnail_cropping_custom_height', 'option_value' => '3', 'autoload' => 'yes'],
    ['option_id' => 4, 'option_name' => 'woocommerce_thumbnail_image_width', 'option_value' => '300', 'autoload' => 'yes'],
    ['option_id' => 5, 'option_name' => 'woocommerce_maybe_regenerate_images_hash', 'option_value' => 'target-runtime-hash', 'autoload' => 'no'],
    ['option_id' => 6, 'option_name' => 'wp_1_wc_regenerate_images_batch_91', 'option_value' => 'runtime-job', 'autoload' => 'no'],
])->setColumns('wp_options', [
    'option_id' => 'bigint(20) unsigned',
    'option_name' => 'varchar(191)',
    'option_value' => 'longtext',
    'autoload' => 'varchar(20)',
])->setPrimaryKey('wp_options', 'option_id')->setUniqueKey('wp_options', ['option_name']);
$wpdb->addInnoDbTable('wp_options')->addIndex('wp_options', 'option_name', 'option_name', true);

$policy = Policy::load(null, ['woocommerce']);
$interpreter = $policy->interpreters()['woocommerce'] ?? null;
duo_check($interpreter instanceof Woocommerce, 'the shipped digest-bound Woo interpreter drives image-option validation');
if (!$interpreter instanceof Woocommerce) {
    duo_check_summary('WooCommerce thumbnail images');
}
$desired = [
    'woocommerce_thumbnail_cropping' => 'custom',
    'woocommerce_thumbnail_cropping_custom_width' => '1',
    'woocommerce_thumbnail_cropping_custom_height' => '1',
    'woocommerce_thumbnail_image_width' => '500',
];
$records = [];
foreach ($desired as $name => $value) {
    $records[$name] = ['state' => 'present', 'autoload' => 'yes', 'value' => $value];
}
duo_check_same([], woo_thumbnail_repository_diagnostics($interpreter, $records),
    'the repository admits the exact 500-pixel custom-square authored projection');

$materializer = (new ReflectionClass(ApplyFieldMaterializer::class))->newInstanceWithoutConstructor();
\Duo\Db::start_repeatable_read('WooCommerce thumbnail fixture transaction');
$materializer->begin_authored_transaction();
\Duo\CacheInvalidationTransaction::begin();
foreach ($desired as $name => $value) {
    $materializer->upsert_option($name, $materializer->option_wire_value($value), 'yes');
    $store->options[$name] = $value;
    $store->autoload[$name] = 'yes';
}
\Duo\Db::commit('WooCommerce thumbnail fixture transaction commit');
\Duo\CacheInvalidationTransaction::finish();
$materializer->end_authored_transaction();
\Duo\CacheInvalidationTransaction::end();
$optionRows = [];
foreach ($wpdb->rows('wp_options') as $row) {
    $optionRows[(string) $row['option_name']] = (string) $row['option_value'];
}
foreach ($desired as $name => $value) {
    duo_check_same($value, $optionRows[$name] ?? null, "$name materializes as exact native option bytes");
}
duo_check_same('target-runtime-hash', $optionRows['woocommerce_maybe_regenerate_images_hash'] ?? null,
    'authored apply preserves the target regeneration hash byte-for-byte');
duo_check_same('runtime-job', $optionRows['wp_1_wc_regenerate_images_batch_91'] ?? null,
    'authored apply preserves an existing target background job byte-for-byte');

$GLOBALS['wooThumbnailThemeSupport'] = ['thumbnail_image_width' => 450];
$themeRegistered = woo_thumbnail_register_image_size();
wc_get_image_size('woocommerce_thumbnail');
duo_check_same(450, $themeRegistered['width'] ?? null,
    'the real Twenty Twenty-One precedence model keeps its 450px override above an authored option');
$store->options['woocommerce_thumbnail_image_width'] = '300';
wp_cache_delete('size-thumbnail', 'woocommerce');
duo_check_same(450, wc_get_image_size('woocommerce_thumbnail')['width'] ?? null,
    'clearing only size-thumbnail leaves the distinct WooCommerce thumbnail cache alias stale');
$GLOBALS['wooThumbnailThemeSupport'] = null;
woo_thumbnail_clear_size_cache();
duo_check_same(300, wc_get_image_size('thumbnail')['width'] ?? null,
    'process-local theme-support removal exposes the exact option-controlled 300px target');

woo_thumbnail_seed_attachment(90, 800, 800, null);
$staleRegistration = wp_generate_attachment_metadata(90, (string) get_attached_file(90));
duo_check_same([450, 450], [
    $staleRegistration['sizes']['woocommerce_thumbnail']['width'] ?? null,
    $staleRegistration['sizes']['woocommerce_thumbnail']['height'] ?? null,
], 'an option write alone cannot manufacture a 300px preimage after the request registered 450px');
$registered300 = woo_thumbnail_register_image_size();
$metadata300 = wp_generate_attachment_metadata(90, (string) get_attached_file(90));
duo_check_same([300, 300, 300, 300], [
    $registered300['width'] ?? null,
    $registered300['height'] ?? null,
    $metadata300['sizes']['woocommerce_thumbnail']['width'] ?? null,
    $metadata300['sizes']['woocommerce_thumbnail']['height'] ?? null,
], 'a fresh Woo registration makes native metadata generation consume the exact 300px square');
$store->options['woocommerce_thumbnail_image_width'] = '500';
woo_thumbnail_clear_size_cache();
$stale300 = wp_generate_attachment_metadata(90, (string) get_attached_file(90));
duo_check_same(300, $stale300['sizes']['woocommerce_thumbnail']['width'] ?? null,
    'a 300-to-500 option transition stays on the boot-registered 300px size until Woo re-registers it');
$registered500 = woo_thumbnail_register_image_size();
$metadata500 = wp_generate_attachment_metadata(90, (string) get_attached_file(90));
duo_check_same([500, 500, 500, 500], [
    $registered500['width'] ?? null,
    $registered500['height'] ?? null,
    $metadata500['sizes']['woocommerce_thumbnail']['width'] ?? null,
    $metadata500['sizes']['woocommerce_thumbnail']['height'] ?? null,
], 'the next-request Woo registration converges the exact 300-to-500 native product path');

WC_Regenerate_Images::init();
WC_Post_Data::init();
$relevantHooks = [];
foreach (['image_get_intermediate_size', 'wp_generate_attachment_metadata',
    'wp_get_attachment_image_src', 'update_post_metadata'] as $hook) {
    foreach ($store->hooks[$hook] ?? [] as $entry) {
        $callback = $entry['callback'];
        $relevantHooks[$hook][] = [
            'callback' => is_array($callback) ? $callback[0] . '::' . $callback[1] : (string) $callback,
            'priority' => $entry['priority'],
            'accepted_args' => $entry['accepted_args'],
        ];
    }
}
duo_check_same([
    'image_get_intermediate_size' => [[
        'callback' => 'WC_Regenerate_Images::filter_image_get_intermediate_size',
        'priority' => 10,
        'accepted_args' => 3,
    ]],
    'wp_generate_attachment_metadata' => [[
        'callback' => 'WC_Regenerate_Images::add_uncropped_metadata',
        'priority' => 10,
        'accepted_args' => 1,
    ]],
    'wp_get_attachment_image_src' => [[
        'callback' => 'WC_Regenerate_Images::maybe_resize_image',
        'priority' => 10,
        'accepted_args' => 4,
    ]],
    'update_post_metadata' => [[
        'callback' => 'WC_Post_Data::update_post_metadata',
        'priority' => 10,
        'accepted_args' => 5,
    ]],
], $relevantHooks, 'the exact Woo and product-meta callbacks remain active on the real request path');

woo_thumbnail_seed_attachment(101, 800, 600, [300, 300, false], true, true, true, 1);
$metadataBeforeFailure = wp_get_attachment_metadata(101);
$first = wp_get_attachment_image_src(101, 'woocommerce_thumbnail');
duo_check_same([500, 375], [$first[1] ?? null, $first[2] ?? null],
    'an editor failure returns the bounded full-image fallback instead of stale square dimensions');
$metadataAfterFailure = wp_get_attachment_metadata(101);
duo_check($metadataBeforeFailure !== $metadataAfterFailure,
    'an editor-selection failure replaces stale derivative metadata with the native base projection');
duo_check_same([800, 600], [
    $metadataAfterFailure['width'] ?? null,
    $metadataAfterFailure['height'] ?? null,
], 'an editor-selection failure persists the full-image dimensions before returning the bounded fallback');
duo_check((int) ($metadataAfterFailure['filesize'] ?? 0) > 0,
    'an editor-selection failure persists the normal JPEG source filesize in core base metadata');
duo_check_same([], array_keys($metadataAfterFailure['sizes'] ?? []),
    'an editor-selection failure persists no derivative names in the native base metadata');
duo_check_same([1, 1], [
    $GLOBALS['wooThumbnailAttachments'][101]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][101]['metadata_writes'],
], 'the failed request records one editor attempt and one core base-metadata write');

unset($store->options['wp_1_wc_regenerate_images_batch_91']);
$second = wp_get_attachment_image_src(101, 'woocommerce_thumbnail');
duo_check_same([500, 500], [$second[1] ?? null, $second[2] ?? null],
    'the next normal image request retries and returns the exact custom-square projection');
duo_check_same([2, 3], [
    $GLOBALS['wooThumbnailAttachments'][101]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][101]['metadata_writes'],
], 'retry persists the core derivative then Woo commits its uncropped metadata marker');
duo_check_same(false,
    wp_get_attachment_metadata(101)['sizes']['woocommerce_thumbnail']['uncropped'] ?? null,
    'generated cropped metadata records the exact Woo uncropped=false marker');
$third = wp_get_attachment_image_src(101, 'woocommerce_thumbnail');
duo_check_same($second, $third, 'a converged third request is idempotent at the frontend image boundary');
duo_check_same([2, 3], [
    $GLOBALS['wooThumbnailAttachments'][101]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][101]['metadata_writes'],
], 'idempotent read performs no second image-editor or metadata mutation');
duo_check_same(4, WC_Post_Data::$metadataCalls,
    'failure base, retry base, core derivative, and Woo marker writes cross the real product-meta callback');

$frontend = woo_thumbnail_frontend(101);
$rest = woo_thumbnail_rest(101);
$storeApi = woo_thumbnail_store_api(101);
duo_check_same($frontend, $rest, 'WordPress REST image readback observes the same converged thumbnail');
duo_check_same($frontend, $storeApi, 'Woo Store API image readback observes the same converged thumbnail');
duo_check_same([2, 3], [
    $GLOBALS['wooThumbnailAttachments'][101]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][101]['metadata_writes'],
], 'frontend, REST, and Store API repeat reads remain mutation-free');

woo_thumbnail_seed_attachment(102, 800, 600, [300, 300, false], true, false);
$missingFile = wp_get_attachment_image_src(102, 'woocommerce_thumbnail');
duo_check_same([500, 375], [$missingFile[1] ?? null, $missingFile[2] ?? null],
    'a missing source file returns bounded native fallback dimensions');
duo_check_same([0, 0], [
    $GLOBALS['wooThumbnailAttachments'][102]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][102]['metadata_writes'],
], 'missing-file refusal reaches no editor and commits no metadata');
woo_thumbnail_restore_file(102);
$restoredFile = wp_get_attachment_image_src(102, 'woocommerce_thumbnail');
duo_check_same([500, 500], [$restoredFile[1] ?? null, $restoredFile[2] ?? null],
    'restoring the file makes the next request converge without a queue');

woo_thumbnail_seed_attachment(103, 800, 600, null, false, true);
$missingMetadata = wp_get_attachment_image_src(103, 'woocommerce_thumbnail');
duo_check_same([800, 600], [$missingMetadata[1] ?? null, $missingMetadata[2] ?? null],
    'missing attachment metadata preserves the usable original instead of inventing dimensions');
duo_check_same([0, 0], [
    $GLOBALS['wooThumbnailAttachments'][103]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][103]['metadata_writes'],
], 'missing metadata performs no unsafe regeneration');
$GLOBALS['wooThumbnailAttachments'][103]['metadata'] = [
    'file' => $GLOBALS['wooThumbnailAttachments'][103]['relative_file'],
    'width' => 800,
    'height' => 600,
    'sizes' => [],
];
$restoredMetadata = wp_get_attachment_image_src(103, 'woocommerce_thumbnail');
duo_check_same([500, 500], [$restoredMetadata[1] ?? null, $restoredMetadata[2] ?? null],
    'a later request converges after native metadata repair');

woo_thumbnail_seed_attachment(104, 800, 800, [300, 300, false]);
$sameAspect = wp_get_attachment_image_src(104, 'woocommerce_thumbnail');
duo_check_same([500, 500], [$sameAspect[1] ?? null, $sameAspect[2] ?? null],
    'the 300-to-500 same-aspect change returns the exact requested dimensions from the full image');
duo_check_same([0, 0], [
    $GLOBALS['wooThumbnailAttachments'][104]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][104]['metadata_writes'],
], 'same-aspect width convergence needs no derivative or background queue');
duo_check_same($sameAspect, wp_get_attachment_image_src(104, 'woocommerce_thumbnail'),
    'same-aspect full-image convergence is idempotent');

woo_thumbnail_seed_attachment(105, 240, 180, null);
$smaller = wp_get_attachment_image_src(105, 'woocommerce_thumbnail');
duo_check_same([240, 180], [$smaller[1] ?? null, $smaller[2] ?? null],
    'a smaller original is never zoomed or upscaled to the authored dimensions');
duo_check_same(0, $GLOBALS['wooThumbnailAttachments'][105]['editor_attempts'],
    'the smaller-original guard runs before the image editor');

woo_thumbnail_seed_attachment(106, 800, 600, [500, 500, false]);
$store->options['woocommerce_thumbnail_cropping'] = 'uncropped';
woo_thumbnail_register_image_size();
$uncropped = wp_get_attachment_image_src(106, 'woocommerce_thumbnail');
duo_check_same([500, 375], [$uncropped[1] ?? null, $uncropped[2] ?? null],
    'cropped-to-uncropped transition follows the original aspect ratio on the next request');
duo_check_same(0, $GLOBALS['wooThumbnailAttachments'][106]['editor_attempts'],
    'uncropped full-image convergence remains independent of the background queue');
$uncroppedGenerated = apply_filters('wp_generate_attachment_metadata', [
    'sizes' => ['woocommerce_thumbnail' => ['width' => 500, 'height' => 375]],
]);
duo_check_same(true, $uncroppedGenerated['sizes']['woocommerce_thumbnail']['uncropped'] ?? null,
    'the native metadata filter marks newly generated uncropped thumbnails');

$store->options['woocommerce_thumbnail_cropping'] = 'custom';
$store->options['woocommerce_thumbnail_cropping_custom_width'] = '4';
$store->options['woocommerce_thumbnail_cropping_custom_height'] = '3';
woo_thumbnail_register_image_size();
woo_thumbnail_seed_attachment(107, 800, 600, [500, 500, false]);
$customRatio = wp_get_attachment_image_src(107, 'woocommerce_thumbnail');
duo_check_same([500, 375], [$customRatio[1] ?? null, $customRatio[2] ?? null],
    'square-to-custom 4:3 transition converges through the normal image-source path');

unset(
    $store->options['woocommerce_thumbnail_cropping'],
    $store->options['woocommerce_thumbnail_cropping_custom_width'],
    $store->options['woocommerce_thumbnail_cropping_custom_height'],
    $store->options['woocommerce_thumbnail_image_width']
);
woo_thumbnail_register_image_size();
duo_check_same(['width' => 300, 'height' => 300, 'crop' => 1], wc_get_image_size('woocommerce_thumbnail'),
    'deleted/absent image options restore the exact 300-pixel square reader defaults');
woo_thumbnail_seed_attachment(108, 800, 800, [300, 300, false]);
duo_check_same([300, 300], array_slice((array) wp_get_attachment_image_src(108, 'woocommerce_thumbnail'), 1, 2),
    'default-state frontend readback remains exact and performs no regeneration');

$store->options['woocommerce_thumbnail_cropping'] = 'custom';
$store->options['woocommerce_thumbnail_cropping_custom_width'] = '1';
$store->options['woocommerce_thumbnail_cropping_custom_height'] = '1';
$store->options['woocommerce_thumbnail_image_width'] = '500';
woo_thumbnail_register_image_size();
woo_thumbnail_seed_attachment(109, 800, 600, [300, 300, false], true, true, false);
$nonImage = wp_get_attachment_image_src(109, 'woocommerce_thumbnail');
duo_check_same([500, 375], [$nonImage[1] ?? null, $nonImage[2] ?? null],
    'a non-image attachment remains on the safe bounded fallback path');
duo_check_same([0, 0], [
    $GLOBALS['wooThumbnailAttachments'][109]['editor_attempts'],
    $GLOBALS['wooThumbnailAttachments'][109]['metadata_writes'],
], 'non-image refs never reach regeneration or metadata writes');

duo_check_same('target-runtime-hash', $store->options['woocommerce_maybe_regenerate_images_hash'] ?? null,
    'request-time convergence never rewrites the target Customizer hash');
duo_check(!array_key_exists('wp_1_wc_regenerate_images_batch_91', $store->options),
    'successful retry remains independent after the simulated background queue is killed');

duo_check_summary('WooCommerce thumbnail images');
