<?php
declare(strict_types=1);

/** Exact WooCommerce 11.x product-attribute repository boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../manifests/interpreters/woocommerce.php';

use Duo\Interpreters\Woocommerce;
use Duo\Policy;

/** @return array<string,mixed> */
function woo_readiness_attribute(array $changes = []): array {
    return array_replace([
        'name' => 'pa_duo-size',
        'value' => '',
        'position' => 0,
        'is_visible' => 1,
        'is_variation' => 1,
        'is_taxonomy' => 1,
    ], $changes);
}

/** @return array<string,mixed> */
function woo_readiness_entity(
    mixed $value,
    string $type = 'product',
    string $metaKey = '_product_attributes'
): array {
    return [
        'type' => 'post',
        'path' => "state/posts/$type/11111111-1111-4111-8111-111111111111--catalog-item.md",
        'data' => [
            'type' => $type,
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'slug' => 'catalog-item',
            'meta' => [$metaKey => $value],
        ],
        'body' => 'Long UTF-8 product body 東京 🚀 مرحبا',
    ];
}

/** @return list<array<string,mixed>> */
function woo_readiness_diagnostics(
    Woocommerce $interpreter,
    mixed $value,
    string $type = 'product',
    string $metaKey = '_product_attributes'
): array {
    return $interpreter->repository_diagnostics([woo_readiness_entity($value, $type, $metaKey)]);
}

/** @return list<string> */
function woo_readiness_messages(array $diagnostics): array {
    return array_map(static fn(array $diagnostic): string => (string) $diagnostic['message'], $diagnostics);
}

function woo_readiness_reports(
    Woocommerce $interpreter,
    mixed $value,
    string $fragment,
    string $type = 'product',
    string $metaKey = '_product_attributes'
): void {
    $diagnostics = woo_readiness_diagnostics($interpreter, $value, $type, $metaKey);
    duo_check(
        $diagnostics !== []
            && count(array_filter(
                $diagnostics,
                static fn(array $d): bool => ($d['code'] ?? null) !== 'adapter_schema_content_mismatch'
            )) === 0
            && str_contains(implode(' | ', woo_readiness_messages($diagnostics)), $fragment),
        "malformed product attributes refuse with the digest-bound schema diagnostic: $fragment"
    );
}

$policy = Policy::load(null, ['woocommerce']);
$interpreter = $policy->interpreters()['woocommerce'] ?? null;
duo_check($interpreter instanceof Woocommerce, 'the shipped WooCommerce manifest resolves its digest-bound interpreter');
if (!$interpreter instanceof Woocommerce) {
    duo_check_summary('WooCommerce production readiness');
}

duo_check_same(
    $policy->post_meta_rule('_product_attributes'),
    $interpreter->post_meta_rule('_product_attributes', []),
    'the interpreter preserves the authored order-preserving static rule'
);
duo_check_same(
    ['class' => 'authored', 'plain_data' => true],
    $interpreter->post_meta_rule('_downloadable_files', []),
    'download rows use recursive plain-data URL rebinding rather than opaque serialized passthrough'
);
duo_check_same(null, $interpreter->post_meta_rule('_sku', []), 'unrelated WooCommerce meta defers to ordinary policy');

$global = ['pa_duo-size' => woo_readiness_attribute()];
duo_check_same([], woo_readiness_diagnostics($interpreter, $global), 'the exact WooCommerce 11.x global-attribute row is clean');
$multibyteGlobal = ['pa_尺寸' => woo_readiness_attribute(['name' => 'pa_尺寸'])];
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, $multibyteGlobal),
    'the exact pa_尺寸 Woo-native multibyte global-attribute taxonomy survives interpreter readiness'
);
duo_check_same([], woo_readiness_diagnostics($interpreter, []), 'the native empty attribute map remains valid');
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, [
        'custom-material' => woo_readiness_attribute([
            'name' => 'Custom Material 東京',
            'value' => 'Cotton | Wool | 麻',
            'position' => 2147483647,
            'is_variation' => 0,
            'is_taxonomy' => 0,
        ]),
    ]),
    'local UTF-8 attributes and large positions remain portable inside the exact core row schema'
);

woo_readiness_reports($interpreter, 'malformed-string', 'must be an object');
woo_readiness_reports($interpreter, [woo_readiness_attribute()], 'not a positional list');
woo_readiness_reports($interpreter, [7 => woo_readiness_attribute()], 'keys must be non-empty strings');
woo_readiness_reports($interpreter, ['pa_duo-size' => ['name', 'value']], 'rows must be named objects');

$missing = woo_readiness_attribute();
unset($missing['position'], $missing['is_visible']);
woo_readiness_reports($interpreter, ['pa_duo-size' => $missing], 'missing required field(s): position, is_visible');
woo_readiness_reports(
    $interpreter,
    ['pa_duo-size' => woo_readiness_attribute(['extension_plain_field' => ['addon' => true]])],
    'unsupported addon-owned field(s): extension_plain_field'
);
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['name' => 7])], 'name must be a non-empty string');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['value' => ['not' => 'text']])], 'value must be a string');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['position' => -1])], 'position must be a non-negative integer');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['position' => '9007199254740993'])], 'position must be a non-negative integer');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['is_visible' => true])], 'is_visible must be integer 0 or 1');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['is_variation' => 2])], 'is_variation must be integer 0 or 1');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['is_taxonomy' => -1])], 'is_taxonomy must be integer 0 or 1');
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['name' => 'pa_other'])], 'must equal its pa_* object key');
woo_readiness_reports($interpreter, ['PA_DUO_SIZE' => woo_readiness_attribute(['name' => 'PA_DUO_SIZE'])], 'must equal its pa_* object key');
woo_readiness_reports($interpreter, ['pa_Pa色' => woo_readiness_attribute(['name' => 'pa_Pa色'])], 'must equal its pa_* object key');
woo_readiness_reports($interpreter, ['pa_★' => woo_readiness_attribute(['name' => 'pa_★'])], 'must equal its pa_* object key');
woo_readiness_reports($interpreter, ['pa_Ⅷ' => woo_readiness_attribute(['name' => 'pa_Ⅷ'])], 'must equal its pa_* object key');
woo_readiness_reports($interpreter, ['pa_-color' => woo_readiness_attribute(['name' => 'pa_-color'])], 'must equal its pa_* object key');
$overlongTaxonomy = 'pa_' . str_repeat('尺', 10);
woo_readiness_reports(
    $interpreter,
    [$overlongTaxonomy => woo_readiness_attribute(['name' => $overlongTaxonomy])],
    'must equal its pa_* object key'
);
$invalidUtf8Taxonomy = "pa_\xFF";
woo_readiness_reports(
    $interpreter,
    [$invalidUtf8Taxonomy => woo_readiness_attribute(['name' => $invalidUtf8Taxonomy])],
    'must equal its pa_* object key'
);
woo_readiness_reports($interpreter, ['pa_duo-size' => woo_readiness_attribute(['value' => 'source-local option'])], 'global attribute value must be empty');
woo_readiness_reports($interpreter, $global, 'valid only on product entities', 'product_variation');

$download = [
    '0123456789abcdef0123456789abcdef' => [
        'id' => '0123456789abcdef0123456789abcdef',
        'name' => 'Portable catalog 日本語.pdf',
        'file' => '{{home}}/wp-content/uploads/2030/01/catalog.pdf?download=1',
        'enabled' => true,
    ],
];
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, $download, 'product_variation', '_downloadable_files'),
    'current downloadable-file rows are valid on products and variations'
);
$legacyDownload = $download;
unset(
    $legacyDownload['0123456789abcdef0123456789abcdef']['id'],
    $legacyDownload['0123456789abcdef0123456789abcdef']['enabled']
);
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, $legacyDownload, 'product', '_downloadable_files'),
    'still-readable legacy name/file download rows remain valid'
);
woo_readiness_reports($interpreter, 'opaque-download', 'downloadable files must be an object', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, [['name' => 'x', 'file' => 'y']], 'not a positional list', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, [str_repeat('x', 129) => ['name' => 'x', 'file' => 'y']], 'at most 128 bytes', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['file' => 'https://example.test/a']], 'downloadable-file name must be a string', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['name' => 'A', 'file' => 7]], 'downloadable-file file must be a string', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['name' => 'A', 'file' => '']], 'file must be non-empty', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['name' => 'A', 'file' => '[private_download id="7"]']], 'shortcode download locators', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['id' => 'download-b', 'name' => 'A', 'file' => 'a']], 'id must equal its object key', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['name' => 'A', 'file' => 'a', 'enabled' => 1]], 'enabled must be boolean', 'product', '_downloadable_files');
woo_readiness_reports($interpreter, ['download-a' => ['name' => 'A', 'file' => 'a', 'enabled' => false]], 'site-local approval state', 'product', '_downloadable_files');
woo_readiness_reports(
    $interpreter,
    ['download-a' => ['name' => 'A', 'file' => 'a', 'addon_checksum' => str_repeat('f', 64)]],
    'unsupported addon-owned field(s): addon_checksum',
    'product',
    '_downloadable_files'
);
woo_readiness_reports(
    $interpreter,
    $download,
    'valid only on product or product_variation entities',
    'shop_coupon',
    '_downloadable_files'
);

$multi = woo_readiness_diagnostics($interpreter, [
    'pa_duo-size' => woo_readiness_attribute(['position' => -1, 'is_visible' => 3]),
]);
duo_check_same(2, count($multi), 'one corrupted row reports every independent field violation in one compiler pass');
duo_check_same(
    ['meta._product_attributes.pa_duo-size.position', 'meta._product_attributes.pa_duo-size.is_visible'],
    array_column($multi, 'locator'),
    'field diagnostics point at the exact canonical locations a reviewer must repair'
);

duo_check_summary('WooCommerce production readiness');
