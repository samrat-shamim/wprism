<?php
declare(strict_types=1);

/** Exact WooCommerce 11.0.0/11.0.1 product-attribute repository boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 3);
}

$repoRoot = dirname(__DIR__, 4);
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../adapter-packages/woocommerce/package/runtime/interpreters/woocommerce.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SidebarState.php';
require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryAuthorization.php';

use Duo\Interpreters\Woocommerce;
use Duo\Policy;
use Duo\Canon;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

$GLOBALS['wooReadinessBlogId'] = 1;
$GLOBALS['wooReadinessNativeUrlCalls'] = [];
$GLOBALS['wooReadinessNativeTextCalls'] = [];
$GLOBALS['wooReadinessNativeHtmlCalls'] = [];
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int {
        return (int) ($GLOBALS['wooReadinessBlogId'] ?? 1);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, ?array $protocols = null): string {
        $GLOBALS['wooReadinessNativeUrlCalls'][] = [$url, $protocols];
        if ($url === '') {
            return '';
        }
        $url = str_replace(' ', '%20', ltrim($url));
        $url = (string) preg_replace("~[^a-z0-9\-+_.?#=!&;,/:%@$|*'()\[\]\\x80-\\xff]~i", '', $url);
        do {
            $before = $url;
            $url = str_ireplace(['%0d', '%0a'], '', $url);
        } while ($url !== $before);
        $url = str_replace(';//', '://', $url);
        return str_replace(['[', ']'], ['%5B', '%5D'], $url);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        $GLOBALS['wooReadinessNativeTextCalls'][] = $value;
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\r\n\t ]+/', ' ', $value);
        $value = trim($value);
        do {
            $before = $value;
            $value = (string) preg_replace('/%[a-f0-9]{2}/i', '', $value);
        } while ($value !== $before);
        return trim((string) preg_replace('/ +/', ' ', $value));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string {
        $GLOBALS['wooReadinessNativeHtmlCalls'][] = $value;
        return strip_tags($value, '<a><br><em><strong>');
    }
}
final class WooReadinessWpdb {
    public function get_blog_prefix(int $blogId): string {
        return $blogId === 1 ? 'wp_' : "wp_{$blogId}_";
    }
}

$GLOBALS['wpdb'] = new WooReadinessWpdb();

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
            'parent' => $type === 'product_variation'
                ? '{{post:11111111-1111-4111-8111-111111111112}}'
                : null,
            'meta' => [$metaKey => $value],
            'terms' => $type === 'product'
                ? ['product_type' => ['20000000-0000-4000-8000-000000000001']]
                : [],
        ],
        'body' => 'Long UTF-8 product body 東京 🚀 مرحبا',
    ];
}

/** @return list<array<string,mixed>> */
function woo_readiness_visibility_terms(): array {
    $entities = [];
    foreach ([
        'exclude-from-search',
        'exclude-from-catalog',
        'featured',
        'outofstock',
        'rated-1',
        'rated-2',
        'rated-3',
        'rated-4',
        'rated-5',
    ] as $index => $slug) {
        $uuid = sprintf('30000000-0000-4000-8000-%012d', $index + 1);
        $entities[] = [
            'type' => 'term',
            'path' => "state/terms/product_visibility/$uuid--$slug.json",
            'data' => [
                'taxonomy' => 'product_visibility',
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => $slug,
                'meta' => [],
            ],
        ];
    }
    return $entities;
}

/** @return list<array<string,mixed>> */
function woo_readiness_product_type_terms(): array {
    return [[
        'type' => 'term',
        'path' => 'state/terms/product_type/20000000-0000-4000-8000-000000000001--simple.json',
        'data' => [
            'taxonomy' => 'product_type',
            'uuid' => '20000000-0000-4000-8000-000000000001',
            'slug' => 'simple',
            'name' => 'simple',
            'meta' => [],
        ],
    ]];
}

/** @return array<string,mixed> */
function woo_readiness_valid_variation_parent(): array {
    return [
        'type' => 'post',
        'path' => 'state/posts/product/11111111-1111-4111-8111-111111111112--variation-parent.md',
        'data' => [
            'type' => 'product',
            'uuid' => '11111111-1111-4111-8111-111111111112',
            'slug' => 'variation-parent',
            'meta' => [],
            'terms' => ['product_type' => ['20000000-0000-4000-8000-000000000002']],
        ],
        'body' => '',
    ];
}

/** @return array<string,mixed> */
function woo_readiness_valid_variable_type_term(): array {
    return [
        'type' => 'term',
        'path' => 'state/terms/product_type/20000000-0000-4000-8000-000000000002--variable.json',
        'data' => [
            'taxonomy' => 'product_type',
            'uuid' => '20000000-0000-4000-8000-000000000002',
            'slug' => 'variable',
            'name' => 'variable',
            'meta' => [],
        ],
    ];
}

/** @return list<array<string,mixed>> */
function woo_readiness_diagnostics(
    Woocommerce $interpreter,
    mixed $value,
    string $type = 'product',
    string $metaKey = '_product_attributes'
): array {
    $entities = [woo_readiness_entity($value, $type, $metaKey)];
    $typeTerms = [];
    if ($type === 'product') {
        $typeTerms = woo_readiness_product_type_terms();
    } elseif ($type === 'product_variation') {
        $entities = array_merge([woo_readiness_valid_variation_parent()], $entities);
        $typeTerms = [woo_readiness_valid_variable_type_term()];
    }
    return $interpreter->repository_diagnostics(array_merge(
        $entities,
        woo_readiness_visibility_terms(),
        $typeTerms
    ));
}

/** @return array<string,mixed> */
function woo_readiness_term_entity(string $taxonomy, array $meta): array {
    return [
        'type' => 'term',
        'path' => "state/terms/$taxonomy/22222222-2222-4222-8222-222222222222--term.json",
        'data' => [
            'taxonomy' => $taxonomy,
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'slug' => 'portable-term',
            'name' => 'Portable 東京 term',
            'meta' => $meta,
        ],
    ];
}

/** @return list<array<string,mixed>> */
function woo_readiness_term_diagnostics(
    Woocommerce $interpreter,
    string $taxonomy,
    array $meta,
    array $relatedPosts = []
): array {
    return $interpreter->repository_diagnostics(array_merge(
        [woo_readiness_term_entity($taxonomy, $meta)],
        $relatedPosts,
        woo_readiness_visibility_terms()
    ));
}

/** @return array<string,mixed> */
function woo_readiness_post_entity(
    string $uuid,
    string $type = 'attachment',
    string $mime = 'image/png',
    string $file = '2030/01/portable.png'
): array {
    return [
        'type' => 'post',
        'path' => "state/posts/$type/$uuid--portable.md",
        'data' => [
            'type' => $type,
            'uuid' => $uuid,
            'slug' => 'portable',
            'mime' => $mime,
            'file' => $file,
            'meta' => [],
        ],
        'body' => '',
    ];
}

/** @return list<array<string,mixed>> */
function woo_readiness_option_diagnostics(Woocommerce $interpreter, array $records): array {
    return $interpreter->repository_diagnostics([[
        'type' => 'options',
        'path' => 'state/options/woocommerce.json',
        'data' => ['records' => $records],
    ]]);
}

/** @return list<string> */
function woo_readiness_messages(array $diagnostics): array {
    return array_map(static fn(array $diagnostic): string => (string) $diagnostic['message'], $diagnostics);
}

/**
 * Build a captured-style repository and send it through the production
 * compiler.  The direct interpreter fixture above is intentionally cheap,
 * but it cannot prove that Canon parsing, identity indexing, reference
 * validation, and Policy::repository_constraint_diagnostics() reach the same
 * Woo parent invariant as a real compile does.
 */
function woo_readiness_compile_put(string $root, string $relative, string $bytes): void {
    Canon::write_file(rtrim($root, '/') . '/' . ltrim($relative, '/'), $bytes);
}

function woo_readiness_compile_post_front(
    string $uuid,
    string $type,
    string $slug,
    array $terms = []
): array {
    return [
        'author' => 'user:admin',
        'comment_status' => 'open',
        'date' => '2026-08-06 00:00:00',
        'date_gmt' => '2026-08-06 00:00:00',
        'excerpt' => '',
        'menu_order' => 0,
        'meta' => [],
        'modified_gmt' => '2026-08-06 00:00:00',
        'parent' => null,
        'ping_status' => 'closed',
        'slug' => $slug,
        'status' => 'publish',
        'terms' => $terms,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'type' => $type,
        'uuid' => $uuid,
    ];
}

function woo_readiness_compile_term_front(
    string $uuid,
    string $taxonomy,
    string $slug
): array {
    return [
        'description' => '',
        'meta' => [],
        'name' => $slug,
        'parent' => null,
        'relationships' => [],
        'slug' => $slug,
        'taxonomy' => $taxonomy,
        'uuid' => $uuid,
    ];
}

/** @return array{root:string,variation_path:string} */
function woo_readiness_compile_repository(string $parentShape): array {
    $root = sys_get_temp_dir() . '/duo_woo_variation_parent_' . $parentShape . '_' . bin2hex(random_bytes(5));
    mkdir($root . '/media', 0777, true);
    register_shutdown_function(static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        @rmdir($root);
    });
    woo_readiness_compile_put($root, 'site.duo.json', Canon::encode([
        'manifests' => ['woocommerce'],
        'policy' => [
            'options' => (object) [],
            'post_meta' => (object) [],
            'term_meta' => (object) [],
            'post_types' => ['product', 'product_variation'],
            'taxonomies' => ['product_type', 'product_visibility'],
        ],
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    $parentUuid = '51000000-0000-4000-8000-000000000001';
    $variationUuid = '51000000-0000-4000-8000-000000000002';
    $variableTypeUuid = '52000000-0000-4000-8000-000000000001';
    $simpleTypeUuid = '52000000-0000-4000-8000-000000000002';
    $parentTypeUuid = $parentShape === 'non-variable' ? $simpleTypeUuid : $variableTypeUuid;

    $parentType = $parentShape === 'wrong-target-type' ? 'post' : 'product';
    $parentTerms = $parentType === 'product'
        ? ['product_type' => [$parentTypeUuid]]
        : [];
    $parentFront = woo_readiness_compile_post_front(
        $parentUuid,
        $parentType,
        'captured-variable-parent',
        $parentTerms
    );
    if ($parentShape !== 'missing-target') {
        woo_readiness_compile_put(
            $root,
            "state/posts/$parentType/$parentUuid--captured-variable-parent.md",
            Canon::post_file($parentFront, '')
        );
    }

    $variationFront = woo_readiness_compile_post_front(
        $variationUuid,
        'product_variation',
        'captured-variation',
        ['product_visibility' => ['30000000-0000-4000-8000-000000000004']]
    );
    switch ($parentShape) {
        case 'canonical':
            $variationFront['parent'] = '{{post:' . $parentUuid . '}}';
            break;
        case 'raw':
            $variationFront['parent'] = $parentUuid;
            break;
        case 'malformed':
            $variationFront['parent'] = '{{post:not-a-uuid}}';
            break;
        case 'null':
            $variationFront['parent'] = null;
            break;
        case 'absent':
            unset($variationFront['parent']);
            break;
        case 'missing-target':
            $variationFront['parent'] = '{{post:' . $parentUuid . '}}';
            break;
        case 'wrong-target-type':
        case 'non-variable':
            $variationFront['parent'] = '{{post:' . $parentUuid . '}}';
            break;
        default:
            throw new \InvalidArgumentException("unknown Woo parent fixture '$parentShape'");
    }
    $variationPath = "state/posts/product_variation/$variationUuid--captured-variation.md";
    woo_readiness_compile_put($root, $variationPath, Canon::post_file($variationFront, ''));

    foreach (woo_readiness_visibility_terms() as $term) {
        $termData = woo_readiness_compile_term_front(
            (string) $term['data']['uuid'],
            'product_visibility',
            (string) $term['data']['slug']
        );
        woo_readiness_compile_put($root, $term['path'], Canon::encode($termData));
    }
    foreach (['variable' => $variableTypeUuid, 'simple' => $simpleTypeUuid] as $slug => $uuid) {
        $term = woo_readiness_compile_term_front($uuid, 'product_type', $slug);
        woo_readiness_compile_put(
            $root,
            "state/terms/product_type/$uuid--$slug.json",
            Canon::encode($term)
        );
    }

    return ['root' => $root, 'variation_path' => substr($variationPath, strlen('state/'))];
}

function woo_readiness_compile_has_parent_diagnostic(
    RepositoryCompilationException $failure,
    string $path,
    string $locator,
    string $message
): bool {
    foreach ($failure->diagnostics as $diagnostic) {
        if (($diagnostic['code'] ?? null) === 'adapter_schema_content_mismatch'
            && ($diagnostic['path'] ?? null) === $path
            && ($diagnostic['locator'] ?? null) === $locator
            && ($diagnostic['message'] ?? null) === $message) {
            return true;
        }
    }
    return false;
}

function woo_readiness_compile_expect_parent_refusal(
    string $shape,
    string $locator,
    string $message
): void {
    $fixture = woo_readiness_compile_repository($shape);
    $policy = Policy::load(
        $fixture['root'],
        ['woocommerce'],
        adapterLibrary: \Duo\AdapterLibrary::fromSourcePackage($GLOBALS['repoRoot'], 'woocommerce')
    );
    try {
        RepositoryCompiler::compile($fixture['root'], $policy);
        duo_check(false, "RepositoryCompiler accepts invalid Woo variation parent shape '$shape'");
    } catch (RepositoryCompilationException $failure) {
        $matched = woo_readiness_compile_has_parent_diagnostic($failure, $fixture['variation_path'], $locator, $message);
        duo_check(
            $matched,
            "RepositoryCompiler refuses Woo variation parent shape '$shape' with the exact parent diagnostic"
                . ($matched ? '' : ': ' . implode(' | ', array_map(
                    static fn(array $diagnostic): string => ($diagnostic['path'] ?? '') . ':'
                        . ($diagnostic['locator'] ?? '') . '=' . ($diagnostic['message'] ?? ''),
                    $failure->diagnostics
                )))
        );
    }
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

$policy = Policy::load(
    null,
    ['woocommerce'],
    adapterLibrary: \Duo\AdapterLibrary::fromSourcePackage($repoRoot, 'woocommerce')
);
$interpreter = $policy->interpreters()['woocommerce'] ?? null;
duo_check($interpreter instanceof Woocommerce, 'the shipped WooCommerce manifest resolves its digest-bound interpreter');
if (!$interpreter instanceof Woocommerce) {
    duo_check_summary('WooCommerce production readiness');
}

$visibilityTerms = woo_readiness_visibility_terms();
$visibilityUuids = [];
foreach ($visibilityTerms as $visibilityTerm) {
    $visibilityUuids[(string) $visibilityTerm['data']['slug']] = (string) $visibilityTerm['data']['uuid'];
}
$posVisibilityUuid = '40000000-0000-4000-8000-000000000001';
$variableTypeUuid = '40000000-0000-4000-8000-000000000002';
$posVisibilityTerm = [
    'type' => 'term',
    'path' => "state/terms/pos_product_visibility/$posVisibilityUuid--pos-hidden.json",
    'data' => [
        'taxonomy' => 'pos_product_visibility',
        'uuid' => $posVisibilityUuid,
        'slug' => 'pos-hidden',
        'name' => 'pos-hidden',
        'meta' => [],
    ],
];
$variableTypeTerm = [
    'type' => 'term',
    'path' => "state/terms/product_type/$variableTypeUuid--variable.json",
    'data' => [
        'taxonomy' => 'product_type',
        'uuid' => $variableTypeUuid,
        'slug' => 'variable',
        'name' => 'variable',
        'meta' => [],
    ],
];
$simpleTypeTerm = woo_readiness_product_type_terms()[0];
$simpleTypeUuid = (string) $simpleTypeTerm['data']['uuid'];
$visibilityParentUuid = '40000000-0000-4000-8000-000000000003';
$visibilityChildUuid = '40000000-0000-4000-8000-000000000004';
$visibilityParent = [
    'type' => 'post',
    'path' => "state/posts/product/$visibilityParentUuid--visibility-parent.md",
    'data' => [
        'type' => 'product',
        'uuid' => $visibilityParentUuid,
        'slug' => 'visibility-parent',
        'meta' => ['_downloadable' => 'no'],
        'terms' => [
            'product_type' => [$variableTypeUuid],
            'product_visibility' => [
                $visibilityUuids['exclude-from-catalog'],
                $visibilityUuids['featured'],
                $visibilityUuids['rated-5'],
            ],
            'pos_product_visibility' => [$posVisibilityUuid],
        ],
    ],
    'body' => '',
];
$visibilityChild = [
    'type' => 'post',
    'path' => "state/posts/product_variation/$visibilityChildUuid--visibility-child.md",
    'data' => [
        'type' => 'product_variation',
        'uuid' => $visibilityChildUuid,
        'parent' => '{{post:' . $visibilityParentUuid . '}}',
        'slug' => 'visibility-child',
        'meta' => [],
        'terms' => [
            'product_visibility' => [$visibilityUuids['outofstock']],
            'pos_product_visibility' => [$posVisibilityUuid],
        ],
    ],
    'body' => '',
];
$visibilityTree = array_merge(
    [$visibilityParent, $visibilityChild, $posVisibilityTerm, $variableTypeTerm],
    $visibilityTerms
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics($visibilityTree),
    'canonical post-token parent resolves while mixed featured/catalog/rating/stock and inherited POS visibility crosses repository readiness'
);

$rawParentTree = $visibilityTree;
$rawParentTree[1]['data']['parent'] = $visibilityParentUuid;
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics($rawParentTree))),
        'exact product parent in the repository'
    ),
    'a raw parent UUID is refused instead of being mistaken for a canonical post reference'
);
$malformedParentTree = $visibilityTree;
$malformedParentTree[1]['data']['parent'] = '{{post:not-a-uuid}}';
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics($malformedParentTree))),
        'exact product parent in the repository'
    ),
    'a malformed parent token is refused before parent-type checks'
);

$compiledCanonicalFixture = woo_readiness_compile_repository('canonical');
try {
    $compiledCanonical = RepositoryCompiler::compile(
        $compiledCanonicalFixture['root'],
        Policy::load(
            $compiledCanonicalFixture['root'],
            ['woocommerce'],
            adapterLibrary: \Duo\AdapterLibrary::fromSourcePackage($repoRoot, 'woocommerce')
        )
    );
    duo_check(
        isset($compiledCanonical->tree()['51000000-0000-4000-8000-000000000002']),
        'RepositoryCompiler compiles a captured-style Woo variation with one canonical post parent'
    );
} catch (\Throwable $failure) {
    duo_check(false, 'RepositoryCompiler compiles a captured-style Woo variation with one canonical post parent: ' . $failure->getMessage());
}
$exactParentDiagnostic = 'WooCommerce variation visibility requires an exact product parent in the repository';
foreach (['raw', 'malformed', 'null', 'absent', 'missing-target', 'wrong-target-type'] as $invalidParentShape) {
    woo_readiness_compile_expect_parent_refusal($invalidParentShape, 'parent', $exactParentDiagnostic);
}
woo_readiness_compile_expect_parent_refusal(
    'non-variable',
    'parent.terms.product_type',
    'WooCommerce variations require exactly one variable product_type parent'
);

$missingVisibilityInventory = $visibilityTree;
array_pop($missingVisibilityInventory);
$missingVisibilityDiagnostics = $interpreter->repository_diagnostics($missingVisibilityInventory);
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($missingVisibilityDiagnostics)),
        'exactly one of every core product_visibility term'
    ),
    'a repository missing one of the exact nine core product-visibility identities refuses readiness'
);
$duplicateVisibilityInventory = $visibilityTree;
$duplicateTerm = $visibilityTerms[0];
$duplicateTerm['data']['uuid'] = '40000000-0000-4000-8000-000000000005';
$duplicateTerm['path'] = 'state/terms/product_visibility/40000000-0000-4000-8000-000000000005--duplicate.json';
$duplicateVisibilityInventory[] = $duplicateTerm;
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages(
            $interpreter->repository_diagnostics($duplicateVisibilityInventory)
        )),
        'exactly one of every core product_visibility term'
    ),
    'a duplicate core product-visibility identity cannot hide behind the right slug'
);

$badRatedParent = $visibilityParent;
$badRatedParent['data']['terms']['product_visibility'][] = $visibilityUuids['rated-4'];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$badRatedParent, $visibilityChild, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'at most one native rated-*'
    ),
    'multiple native rating projection terms refuse at the repository boundary'
);
$badVariation = $visibilityChild;
$badVariation['data']['terms']['product_visibility'][] = $visibilityUuids['featured'];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$visibilityParent, $badVariation, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'variations may carry only'
    ),
    'a variation cannot carry root-only featured/catalog/rating projection terms'
);
$visibleVariation = $visibilityChild;
$visibleVariation['data']['terms']['pos_product_visibility'] = [];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$visibilityParent, $visibleVariation, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'must exactly inherit its variable parent'
    ),
    'variation POS visibility must exactly inherit the variable parent intent'
);
$downloadablePos = $visibilityParent;
$downloadablePos['data']['meta']['_downloadable'] = 'yes';
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$downloadablePos, $visibilityChild, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'non-downloadable simple or variable product'
    ),
    'downloadable products cannot claim portable POS-hidden intent'
);
$duplicateRelationship = $visibilityParent;
$duplicateRelationship['data']['terms']['product_visibility'][] = $visibilityUuids['featured'];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$duplicateRelationship, $visibilityChild, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'malformed or duplicate term identity'
    ),
    'duplicate visibility references refuse before materialization'
);
$missingProductType = $visibilityParent;
$missingProductType['data']['terms']['product_type'] = [];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$missingProductType, $visibilityChild, $posVisibilityTerm, $variableTypeTerm],
            $visibilityTerms
        )))),
        'exactly one admitted core product_type'
    ),
    'a repository product missing its exact core product_type refuses readiness'
);
$multipleProductTypes = $visibilityParent;
$multipleProductTypes['data']['terms']['product_type'][] = $simpleTypeUuid;
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$multipleProductTypes, $visibilityChild, $posVisibilityTerm, $variableTypeTerm, $simpleTypeTerm],
            $visibilityTerms
        )))),
        'exactly one admitted core product_type'
    ),
    'multiple core product_type relationships cannot choose a native product class'
);
$simpleVisibilityParent = $visibilityParent;
$simpleVisibilityParent['data']['terms']['product_type'] = [$simpleTypeUuid];
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$simpleVisibilityParent, $visibilityChild, $posVisibilityTerm, $simpleTypeTerm],
            $visibilityTerms
        )))),
        'exactly one variable product_type parent'
    ),
    'a variation parent must carry the exact variable product_type identity'
);
$malformedProductTypeTerm = $variableTypeTerm;
$malformedProductTypeTerm['data']['slug'] = 'subscription';
$malformedProductTypeTerm['data']['name'] = 'subscription';
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($interpreter->repository_diagnostics(array_merge(
            [$visibilityParent, $visibilityChild, $posVisibilityTerm, $malformedProductTypeTerm],
            $visibilityTerms
        )))),
        'permits only its exact core slug/name identities'
    ),
    'an extension or malformed product_type term remains outside the exact core adapter contract'
);
$missingPosIdentityTree = array_merge(
    [$visibilityParent, $visibilityChild, $variableTypeTerm],
    $visibilityTerms
);
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages(
            $interpreter->repository_diagnostics($missingPosIdentityTree)
        )),
        'does not resolve to the exact taxonomy'
    ),
    'referenced POS visibility requires its exact core pos-hidden identity'
);

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
foreach (['_button_text', '_cogs_total_value', '_cogs_value_is_additive', '_product_url'] as $metaKey) {
    duo_check_same(
        ['class' => 'authored'],
        $interpreter->post_meta_rule($metaKey, []),
        "$metaKey is a reviewed exact WooCommerce 11.0.x authored product field"
    );
}
duo_check_same(
    null,
    $interpreter->post_meta_rule('_wc_additional_variation_images', []),
    'an active extension-owned variation gallery row remains loudly unclassified'
);
duo_check_same(
    ['class' => 'env'],
    $interpreter->post_meta_rule('_wc_additional_variation_images', [
        '_wc_variation_gallery_legacy_fallback_disabled' => 'yes',
    ]),
    'the exact core migration sentinel proves a residual legacy gallery row is inert and nonportable'
);
foreach (['no', 'YES', '', true, 1, ['yes']] as $hostileSentinel) {
    duo_check_same(
        null,
        $interpreter->post_meta_rule('_wc_additional_variation_images', [
            '_wc_variation_gallery_legacy_fallback_disabled' => $hostileSentinel,
        ]),
        'malformed or stale variation-gallery sentinels cannot hide populated extension residue'
    );
}

foreach (['color', 'display_type', 'icon', 'tracking_url_template'] as $metaKey) {
    duo_check_same(
        ['class' => 'authored'],
        $interpreter->term_meta_rule($metaKey, []),
        "$metaKey is an exact WooCommerce-authored term field"
    );
}
duo_check_same(
    ['class' => 'authored', 'lint_ok' => true],
    $interpreter->term_meta_rule('order', []),
    'attribute-term menu order is authored and audited as a numeric ordering scalar rather than an entity reference'
);
duo_check_same(
    ['class' => 'authored', 'ref' => 'post'],
    $interpreter->term_meta_rule('image', []),
    'visual attribute images cross the attachment ledger instead of copying a source id'
);
duo_check_same(
    ['class' => 'derived'],
    $interpreter->term_meta_rule('product_ids', []),
    'Woo product_ids term cache is derived'
);
foreach (['product_cat', 'product_tag', 'product_brand'] as $countedTaxonomy) {
    duo_check_same(
        ['class' => 'derived'],
        $interpreter->term_meta_rule("product_count_$countedTaxonomy", []),
        "Woo's exact _wc_term_recount cache for $countedTaxonomy is derived"
    );
}
foreach (['product_count_', 'product_count_pa_color', 'product_count_product', 'product_count_product_cat_extra'] as $nearMiss) {
    duo_check_same(
        null,
        $interpreter->term_meta_rule($nearMiss, []),
        "$nearMiss is outside Woo core's exact _wc_term_recount key inventory"
    );
}
duo_check_same(
    null,
    $policy->meta_rule_for_post('product_count_product_cat', []),
    'an identically named post-meta row remains unknown instead of inheriting the term-only derived ruling'
);
foreach (['auto_fulfill_downloadable', 'auto_fulfill_virtual'] as $optionName) {
    duo_check_same(
        ['class' => 'authored', 'autoload' => 'preserve'],
        $interpreter->option_rule($optionName, []),
        "$optionName uses the reviewed exact authored option rule"
    );
}
foreach ([
    'pickup_location_pickup_locations',
    'woocommerce_actionable_order_statuses',
    'woocommerce_category_archive_display',
    'woocommerce_checkout_terms_and_conditions_checkbox_text',
    'woocommerce_customer_stock_notifications_allow_signups',
    'woocommerce_customer_stock_notifications_create_account_on_signup',
    'woocommerce_customer_stock_notifications_require_account',
    'woocommerce_customer_stock_notifications_require_double_opt_in',
    'woocommerce_date_type',
    'woocommerce_default_catalog_orderby',
    'woocommerce_default_date_range',
    'woocommerce_email_from_name',
    'woocommerce_enable_order_comments',
    'woocommerce_excluded_report_order_statuses',
    'woocommerce_gateway_order',
    'woocommerce_graphql_apq_enabled',
    'woocommerce_graphql_endpoint_url',
    'woocommerce_graphql_get_endpoint_enabled',
    'woocommerce_graphql_max_query_complexity',
    'woocommerce_graphql_max_query_depth',
    'woocommerce_graphql_object_cache_enabled',
    'woocommerce_graphql_opcache_enabled',
    'woocommerce_graphql_query_cache_ttl',
    'woocommerce_pickup_location_settings',
    'woocommerce_pos_store_name',
    'woocommerce_product_type',
    'woocommerce_rest_api_enable_cache_headers',
    'woocommerce_shop_page_display',
] as $optionName) {
    duo_check_same(
        'authored',
        $policy->option_rule($optionName)['class'] ?? null,
        "$optionName is exact portable merchant-authored WooCommerce state"
    );
}
foreach ([
    'woocommerce_thumbnail_cropping_custom_height',
    'woocommerce_thumbnail_cropping_custom_width',
] as $optionName) {
    duo_check_same(
        true,
        $policy->option_rule($optionName)['lint_ok'] ?? null,
        "$optionName is audited as a numeric Customizer ratio dimension rather than an entity reference"
    );
}
foreach ([
    'pickup_location_pickup_locations',
    'woocommerce_actionable_order_statuses',
    'woocommerce_excluded_report_order_statuses',
    'woocommerce_gateway_order',
    'woocommerce_pickup_location_settings',
] as $optionName) {
    duo_check_same(
        true,
        $policy->option_rule($optionName)['plain_data'] ?? null,
        "$optionName crosses the class-disabled recursive plain-data boundary"
    );
}
foreach (['woocommerce_address_autocomplete_provider', 'woocommerce_rest_api_enable_backend_caching', 'woocommerce_share_key'] as $optionName) {
    duo_check_same(
        ['class' => 'env', 'required' => false, 'autoload' => 'preserve'],
        $policy->meta_rule_for_option($optionName, []),
        "$optionName stays optional target-environment state"
    );
}
duo_check_same(
    ['class' => 'derived', 'autoload' => 'preserve'],
    $policy->meta_rule_for_option('woocommerce_analytics_import_interval', []),
    'the localized analytics import interval label stays derived'
);
$codRule = $policy->meta_rule_for_option('woocommerce_cod_settings', []);
duo_check_same(
    true,
    ($codRule['closed_sub_keys'] ?? null) === true
        && ($codRule['sub_keys']['enable_for_methods']['json_refs'][0]['kind'] ?? null) === 'wc_zone_method',
    'COD settings use the closed native record boundary with typed shipping-zone method references'
);
foreach ([
    'woocommerce_analytics_scheduled_import',
    'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
] as $optionName) {
    duo_check_same(
        'authored',
        $policy->meta_rule_for_option($optionName, [])['class'] ?? null,
        "$optionName is typed portable state with an exact native side-effect provider"
    );
}
duo_check_same(
    ['class' => 'runtime'],
    $interpreter->user_meta_rule('wc_push_notification_preferences_wp', []),
    'the single-site/blog-1 suffix used by Woo remains target-local device state'
);
$GLOBALS['wooReadinessBlogId'] = 7;
duo_check_same(
    ['class' => 'runtime'],
    $interpreter->user_meta_rule('wc_push_notification_preferences_wp_7', []),
    'the exact current multisite-blog suffix remains target-local device state'
);
foreach ([
    'wc_push_notification_preferences_wp',
    'wc_push_notification_preferences_wp_8',
    'wc_push_notification_preferences_arbitrary',
    'wp_7_wc_push_notification_preferences',
    'wc_push_notification_preferences',
] as $foreignKey) {
    duo_check_same(
        null,
        $interpreter->user_meta_rule($foreignKey, []),
        "$foreignKey cannot claim another site's or an extension's user metadata"
    );
}
$GLOBALS['wooReadinessBlogId'] = 1;
duo_check_same(null, $interpreter->user_meta_rule('customer_preferences', []), 'unrelated user metadata remains unclaimed');

$global = ['pa_duo-size' => woo_readiness_attribute()];
duo_check_same([], woo_readiness_diagnostics($interpreter, $global), 'the exact WooCommerce 11.0.x global-attribute row is clean');
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

duo_check_same(
    [],
    woo_readiness_diagnostics(
        $interpreter,
        '{{home}}/partner/東京?campaign=summer#buy',
        'product',
        '_product_url'
    ),
    'an internal external-product URL is tokenized and portable'
);
duo_check_same(
    [],
    woo_readiness_diagnostics(
        $interpreter,
        'https://merchant.example/products/尺寸?campaign=summer',
        'product',
        '_product_url'
    ),
    'a third-party HTTPS external-product URL remains supported'
);
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, 'اشتر الآن — 東京', 'product', '_button_text'),
    'external-product button text preserves bounded Unicode and RTL content'
);
woo_readiness_reports($interpreter, '', 'must be non-empty', 'product', '_product_url');
woo_readiness_reports($interpreter, 7, 'must be a string', 'product', '_product_url');
woo_readiness_reports($interpreter, 'ftp://merchant.example/file', 'HTTP or HTTPS', 'product', '_product_url');
woo_readiness_reports($interpreter, 'https://user:pass@merchant.example/file', 'without credentials', 'product', '_product_url');
woo_readiness_reports($interpreter, "https://merchant.example/a\x01b", 'without controls', 'product', '_product_url');
woo_readiness_reports($interpreter, 'https://merchant.example/' . str_repeat('x', 8193), 'at most 8192 bytes', 'product', '_product_url');
woo_readiness_reports(
    $interpreter,
    'https://merchant.example/item[raw]',
    'must already equal the exact native WordPress URL-sanitized bytes',
    'product',
    '_product_url'
);
woo_readiness_reports($interpreter, 'https://merchant.example/item', 'valid only on product entities', 'product_variation', '_product_url');
woo_readiness_reports($interpreter, ['not' => 'text'], 'must be a string', 'product', '_button_text');
woo_readiness_reports($interpreter, "Buy\x00Now", 'without controls', 'product', '_button_text');
woo_readiness_reports($interpreter, "Buy\xFFNow", 'valid UTF-8', 'product', '_button_text');
woo_readiness_reports($interpreter, str_repeat('x', 4097), 'at most 4096 bytes', 'product', '_button_text');
foreach ([' Buy now ', '<b>Buy now</b>', 'Buy%20now'] as $nonCanonicalButton) {
    woo_readiness_reports(
        $interpreter,
        $nonCanonicalButton,
        'must already equal the exact native WordPress text-sanitized bytes',
        'product',
        '_button_text'
    );
}
duo_check(
    $GLOBALS['wooReadinessNativeUrlCalls'] !== [] && $GLOBALS['wooReadinessNativeTextCalls'] !== [],
    'repository validation executes the native URL and text canonicalization boundaries'
);

foreach (['-0.25', '1.23454', '1.23455', '1.234565', '-1.23455', '1.0E-7', '9.999999999999E+14'] as $cogsValue) {
    duo_check_same(
        [],
        woo_readiness_diagnostics($interpreter, $cogsValue, 'product', '_cogs_total_value'),
        "native authored Cost of Goods postmeta $cogsValue is portable before lookup-column coercion"
    );
}
foreach (['1.2300', '1.0E+3', '0E+9', '0.0000'] as $nonWriterCogsValue) {
    woo_readiness_reports(
        $interpreter,
        $nonWriterCogsValue,
        'exact native float storage spelling',
        'product',
        '_cogs_total_value'
    );
}
duo_check_same(
    [],
    woo_readiness_diagnostics($interpreter, '0', 'product_variation', '_cogs_total_value'),
    'variation Cost of Goods preserves an explicit zero because its native setter disables base-product normalization'
);
woo_readiness_reports(
    $interpreter,
    '0',
    'zero must be represented by metadata absence',
    'product',
    '_cogs_total_value'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(array_merge([
        woo_readiness_valid_variation_parent(),
        woo_readiness_entity('2.5', 'product_variation', '_cogs_total_value'),
        woo_readiness_entity('yes', 'product_variation', '_cogs_value_is_additive'),
        woo_readiness_valid_variable_type_term(),
    ], woo_readiness_visibility_terms())),
    'variation Cost of Goods value and additive inheritance marker are jointly valid'
);
foreach (['01.00', '1e3', '1.0E+15', '1.0E+309', '1.0E-400', str_repeat('9', 129)] as $badCogsValue) {
    woo_readiness_reports(
        $interpreter,
        $badCogsValue,
        'exact native float storage spelling',
        'product',
        '_cogs_total_value'
    );
}
woo_readiness_reports($interpreter, 1.25, 'exact native float storage spelling', 'product', '_cogs_total_value');
woo_readiness_reports($interpreter, '4.00', 'valid only on product or product_variation', 'shop_coupon', '_cogs_total_value');
woo_readiness_reports($interpreter, 'no', "must be exact 'yes'", 'product_variation', '_cogs_value_is_additive');
woo_readiness_reports($interpreter, true, "must be exact 'yes'", 'product_variation', '_cogs_value_is_additive');
woo_readiness_reports($interpreter, 'yes', 'valid only on product_variation', 'product', '_cogs_value_is_additive');
$cogsMarker = 'secret_cogs_marker_DO_NOT_ECHO';
$cogsMarkerDiagnostics = woo_readiness_diagnostics($interpreter, $cogsMarker, 'product', '_cogs_total_value');
duo_check(
    $cogsMarkerDiagnostics !== []
        && !str_contains(implode(' | ', woo_readiness_messages($cogsMarkerDiagnostics)), $cogsMarker),
    'malformed Cost of Goods diagnostics never echo merchant-shaped input'
);

duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'product_cat', [
        'display_type' => 'both',
        'order' => '2147483647',
        'thumbnail_id' => '{{post:33333333-3333-4333-8333-333333333333}}',
    ], [woo_readiness_post_entity('33333333-3333-4333-8333-333333333333')]),
    'product category display, order, and divergent attachment reference are valid together'
);
duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'product_brand', [
        'display_type' => 'both',
        'order' => '17',
        'thumbnail_id' => '{{post:44444444-4444-4444-8444-444444444444}}',
    ], [woo_readiness_post_entity('44444444-4444-4444-8444-444444444444')]),
    'core brand REST display/order and thumbnail fields share the exact portable term boundary'
);
duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'pa_尺寸', ['color' => '#A1b2C3', 'order' => '0']),
    'visual multibyte global attributes preserve exact native color and ordering'
);
duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'pa_尺寸', [
        'image' => '{{post:55555555-5555-4555-8555-555555555555}}',
    ], [woo_readiness_post_entity('55555555-5555-4555-8555-555555555555')]),
    'visual attribute image mode preserves a divergent attachment identity'
);
duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'pa_尺寸', []),
    'removing both visual rows is the exact native none-state transition'
);
foreach ([
    [[], 'a missing/deleted image attachment'],
    [[woo_readiness_post_entity('55555555-5555-4555-8555-555555555555', 'page', '', '')], 'a non-attachment post'],
    [[woo_readiness_post_entity('55555555-5555-4555-8555-555555555555', 'attachment', 'application/pdf', '2030/01/file.pdf')], 'a non-image attachment'],
] as [$relatedPosts, $description]) {
    $imageDiagnostics = woo_readiness_term_diagnostics($interpreter, 'pa_尺寸', [
        'image' => '{{post:55555555-5555-4555-8555-555555555555}}',
    ], $relatedPosts);
    duo_check(
        $imageDiagnostics !== []
            && str_contains(implode(' | ', woo_readiness_messages($imageDiagnostics)), 'live image attachment'),
        "$description cannot satisfy Woo's wp_attachment_is_image semantic boundary"
    );
}
duo_check_same(
    [],
    woo_readiness_term_diagnostics($interpreter, 'wc_fulfillment_shipping_provider', [
        'tracking_url_template' => 'https://carrier.example/track?id=__PLACEHOLDER__',
        'icon' => '{{uploads}}/2030/01/carrier-icon.png',
    ]),
    'custom fulfillment providers preserve portable native HTTP URL fields'
);
foreach ([
    '' => 'an empty native tracking template remains a supported absence value',
    'https://carrier.example/track' => 'a tracking template need not contain a placeholder',
    'https://carrier.example/track?a=__PLACEHOLDER__&b=__PLACEHOLDER__' => 'native replacement permits multiple placeholder occurrences',
    'https://carrier.example/track/%E6%9D%B1%E4%BA%AC?literal=100%25' => 'percent-encoded Unicode and percent data follow the native URL filter',
] as $trackingTemplate => $message) {
    duo_check_same(
        [],
        woo_readiness_term_diagnostics($interpreter, 'wc_fulfillment_shipping_provider', [
            'tracking_url_template' => $trackingTemplate,
        ]),
        $message
    );
}

$termCases = [
    ['category', ['display_type' => 'both'], 'valid only on product_cat'],
    ['product_cat', ['display_type' => 'grid'], 'must be default, products, subcategories, or both'],
    ['product_brand', ['display_type' => 'grid'], 'must be default, products, subcategories, or both'],
    ['product_cat', ['order' => '01'], 'canonical non-negative 32-bit'],
    ['product_cat', ['order' => '2147483648'], 'canonical non-negative 32-bit'],
    ['product_cat', ['color' => '#abc'], 'valid only on global product attribute'],
    ['pa_color', ['color' => 'red'], 'exact three- or six-digit hex'],
    ['pa_color', ['color' => '#abc', 'image' => '{{post:55555555-5555-4555-8555-555555555555}}'], 'mutually exclusive'],
    ['product_tag', ['order' => '1'], 'valid only on product_cat, product_brand, or global product attribute'],
    ['product_tag', ['thumbnail_id' => '{{post:55555555-5555-4555-8555-555555555555}}'], 'valid only on product_cat or product_brand'],
    ['wc_fulfillment_shipping_provider', ['icon' => 'javascript:alert(1)'], 'HTTP or HTTPS'],
    ['wc_fulfillment_shipping_provider', ['tracking_url_template' => 'https://user:secret@carrier.example/t'], 'without credentials'],
    ['wc_fulfillment_shipping_provider', ['tracking_url_template' => 'https://carrier.example/追跡'], 'HTTP or HTTPS'],
    ['wc_fulfillment_shipping_provider', ['icon' => 'https://carrier.example/icon[raw].png'], 'native WordPress URL-sanitized bytes'],
    ['product_cat', ['tracking_url_template' => 'https://carrier.example/t'], 'valid only on wc_fulfillment_shipping_provider'],
];
foreach ($termCases as [$taxonomy, $meta, $fragment]) {
    $diagnostics = woo_readiness_term_diagnostics($interpreter, $taxonomy, $meta);
    duo_check(
        $diagnostics !== [] && str_contains(implode(' | ', woo_readiness_messages($diagnostics)), $fragment),
        "malformed $taxonomy term state refuses with a bounded diagnostic: $fragment"
    );
}
$termSecret = 'https://user:term_secret_marker_DO_NOT_ECHO@carrier.example/t';
$termSecretDiagnostics = woo_readiness_term_diagnostics(
    $interpreter,
    'wc_fulfillment_shipping_provider',
    ['tracking_url_template' => $termSecret]
);
duo_check(
    $termSecretDiagnostics !== []
        && !str_contains(implode(' | ', woo_readiness_messages($termSecretDiagnostics)), 'term_secret_marker_DO_NOT_ECHO'),
    'fulfillment URL refusal never echoes embedded credential-shaped bytes'
);

duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, [
        'auto_fulfill_downloadable' => ['state' => 'present', 'value' => 'yes'],
        'auto_fulfill_virtual' => ['state' => 'present', 'value' => 'no'],
    ]),
    'both native automatic-fulfillment settings accept exact yes/no states'
);
duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, [
        'auto_fulfill_downloadable' => ['state' => 'deleted'],
    ]),
    'automatic-fulfillment option deletion remains portable'
);
$badFulfillmentOption = woo_readiness_option_diagnostics($interpreter, [
    'auto_fulfill_virtual' => ['state' => 'present', 'value' => true],
]);
duo_check(
    $badFulfillmentOption !== []
        && str_contains(implode(' | ', woo_readiness_messages($badFulfillmentOption)), 'must be exact yes or no'),
    'malformed automatic-fulfillment option state refuses'
);

$validPickupLocations = [[
    'name' => '<strong>مخزن</strong> 東京',
    'address' => [
        'address_1' => '١٢ شارع الاختبار',
        'city' => '東京',
        'state' => '13',
        'postcode' => '100-0001',
        'country' => 'JP',
    ],
    'details' => '<strong>بوابة ٢</strong><br>南口',
    'enabled' => true,
]];
$validSettings = [
    'pickup_location_pickup_locations' => [
        'state' => 'present',
        'value' => $validPickupLocations,
    ],
    'woocommerce_actionable_order_statuses' => [
        'state' => 'present',
        'value' => ['processing', 'on-hold', 'merchant-review'],
    ],
    'woocommerce_analytics_scheduled_import' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_category_archive_display' => ['state' => 'present', 'value' => 'both'],
    'woocommerce_checkout_terms_and_conditions_checkbox_text' => [
        'state' => 'present',
        'value' => '<strong>أوافق 東京</strong> [terms]',
    ],
    'woocommerce_customer_stock_notifications_allow_signups' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_customer_stock_notifications_create_account_on_signup' => ['state' => 'present', 'value' => 'no'],
    'woocommerce_customer_stock_notifications_require_account' => ['state' => 'present', 'value' => 'no'],
    'woocommerce_customer_stock_notifications_require_double_opt_in' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold' => [
        'state' => 'present',
        'value' => '3650000',
    ],
    'woocommerce_date_type' => ['state' => 'present', 'value' => 'date_completed'],
    'woocommerce_default_catalog_orderby' => ['state' => 'present', 'value' => 'price-desc'],
    'woocommerce_default_date_range' => [
        'state' => 'present',
        'value' => 'period=month&compare=previous_year',
    ],
    'woocommerce_demo_store_notice' => [
        'state' => 'present',
        'value' => '<strong>افتتاح المتجر 東京</strong><br>الشحن مجاني',
    ],
    'woocommerce_email_from_name' => ['state' => 'present', 'value' => 'متجر 東京'],
    'woocommerce_enable_order_comments' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_excluded_report_order_statuses' => [
        'state' => 'present',
        'value' => ['pending', 'failed', 'cancelled'],
    ],
    'woocommerce_gateway_order' => [
        'state' => 'present',
        'value' => ['stripe' => 0, '_wc_offline_payment_methods_group' => 1, 'bacs' => 2, 'cod' => 3],
    ],
    'woocommerce_graphql_apq_enabled' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_graphql_endpoint_url' => ['state' => 'present', 'value' => 'wc_store/graphql-v2'],
    'woocommerce_graphql_get_endpoint_enabled' => ['state' => 'present', 'value' => 'no'],
    'woocommerce_graphql_max_query_complexity' => ['state' => 'present', 'value' => '1000'],
    'woocommerce_graphql_max_query_depth' => ['state' => 'present', 'value' => '00012'],
    'woocommerce_graphql_object_cache_enabled' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_graphql_opcache_enabled' => ['state' => 'present', 'value' => 'no'],
    'woocommerce_graphql_query_cache_ttl' => ['state' => 'present', 'value' => '3600'],
    'woocommerce_pos_store_name' => ['state' => 'present', 'value' => 'فرع 東京'],
    'woocommerce_product_type' => ['state' => 'present', 'value' => 'external'],
    'woocommerce_pickup_location_settings' => [
        'state' => 'present',
        'value' => [
            'enabled' => 'yes',
            'title' => 'استلام 東京',
            'tax_status' => 'taxable',
            'cost' => '12.50',
        ],
    ],
    'woocommerce_rest_api_enable_cache_headers' => ['state' => 'present', 'value' => 'yes'],
    'woocommerce_shop_page_display' => ['state' => 'present', 'value' => 'subcategories'],
    'woocommerce_single_image_width' => ['state' => 'present', 'value' => '1600'],
    'woocommerce_thumbnail_cropping' => ['state' => 'present', 'value' => 'custom'],
    'woocommerce_thumbnail_cropping_custom_height' => ['state' => 'present', 'value' => '9'],
    'woocommerce_thumbnail_cropping_custom_width' => ['state' => 'present', 'value' => '16'],
    'woocommerce_thumbnail_image_width' => ['state' => 'present', 'value' => '500'],
];
duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, $validSettings),
    'direct-read Woo settings accept exact native enums, arrays, Unicode, HTML, GraphQL, and gateway-order shapes'
);
duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, [
        'woocommerce_gateway_order' => ['state' => 'present', 'value' => []],
        'pickup_location_pickup_locations' => ['state' => 'present', 'value' => []],
        'woocommerce_pickup_location_settings' => ['state' => 'present', 'value' => []],
        'woocommerce_shop_page_display' => ['state' => 'present', 'value' => ''],
        'woocommerce_email_from_name' => ['state' => 'present', 'value' => ''],
        'woocommerce_analytics_scheduled_import' => ['state' => 'deleted'],
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold' => [
            'state' => 'present',
            'value' => '',
        ],
        'woocommerce_graphql_endpoint_url' => ['state' => 'deleted'],
        'woocommerce_single_image_width' => ['state' => 'deleted'],
        'woocommerce_thumbnail_cropping' => ['state' => 'deleted'],
        'woocommerce_thumbnail_cropping_custom_height' => ['state' => 'deleted'],
        'woocommerce_thumbnail_cropping_custom_width' => ['state' => 'deleted'],
        'woocommerce_thumbnail_image_width' => ['state' => 'deleted'],
    ]),
    'native empty maps/text/enums and option deletion remain portable where the exact writer permits them, including every image control'
);
duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, [
        'woocommerce_thumbnail_cropping' => ['state' => 'present', 'value' => '1:1'],
        'woocommerce_thumbnail_cropping_custom_height' => ['state' => 'present', 'value' => '0'],
        'woocommerce_thumbnail_cropping_custom_width' => ['state' => 'present', 'value' => '0'],
        'woocommerce_thumbnail_image_width' => ['state' => 'present', 'value' => '0'],
    ]),
    'Customizer-native zero absint values remain portable and Woo applies its exact max-one ratio semantics'
);
duo_check_same(
    [],
    woo_readiness_option_diagnostics($interpreter, [
        'woocommerce_thumbnail_cropping' => ['state' => 'present', 'value' => 'uncropped'],
        'woocommerce_thumbnail_cropping_custom_height' => ['state' => 'absent'],
        'woocommerce_thumbnail_cropping_custom_width' => ['state' => 'absent'],
        'woocommerce_thumbnail_image_width' => ['state' => 'absent'],
    ]),
    'unstored ratio and width controls retain Woo defaults while uncropped mode remains portable'
);
foreach ([
    ['enabled' => 'yes'],
    ['title' => 'Pickup'],
    ['tax_status' => 'none'],
    ['cost' => '-12.50'],
    ['cost' => '.5'],
    ['cost' => '-.5'],
    ['cost' => str_repeat('9', 1024)],
    ['enabled' => 'no', 'cost' => '0'],
] as $partialPickupSettings) {
    duo_check_same(
        [],
        woo_readiness_option_diagnostics($interpreter, [
            'woocommerce_pickup_location_settings' => [
                'state' => 'present',
                'value' => $partialPickupSettings,
            ],
        ]),
        'each REST-valid local-pickup settings subset survives repository validation and native default completion'
    );
}

$invalidSettings = [
    ['woocommerce_analytics_scheduled_import', 'enabled', 'exact yes or no'],
    ['woocommerce_analytics_scheduled_import', true, 'exact yes or no'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', -1, 'canonical whole days'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', '1.5', 'canonical whole days'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', '1e3', 'canonical whole days'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', ' 1', 'canonical whole days'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', '01', 'canonical whole days'],
    ['woocommerce_customer_stock_notifications_unverified_deletions_days_threshold', '3650001', 'canonical whole days'],
    ['woocommerce_rest_api_enable_cache_headers', true, 'exact yes or no'],
    ['woocommerce_shop_page_display', 'products', 'exact native value set'],
    ['woocommerce_date_type', 'updated_at', 'exact native value set'],
    ['woocommerce_default_catalog_orderby', 'random', 'exact native value set'],
    ['woocommerce_product_type', 'subscription', 'exact native value set'],
    ['woocommerce_actionable_order_statuses', ['processing', 'processing'], 'unique bounded'],
    ['woocommerce_actionable_order_statuses', ['processing', 'wc bad'], 'unique bounded'],
    ['woocommerce_excluded_report_order_statuses', ['key' => 'failed'], 'list of at most'],
    ['woocommerce_default_date_range', "period=month\nsecret", 'native WordPress text-sanitized'],
    ['woocommerce_email_from_name', '<b>store</b>', 'native WordPress text-sanitized'],
    ['woocommerce_graphql_max_query_depth', '0', 'positive PHP-range'],
    ['woocommerce_graphql_max_query_depth', '-1', 'positive PHP-range'],
    ['woocommerce_graphql_max_query_depth', '9223372036854775808', 'positive PHP-range'],
    ['woocommerce_graphql_query_cache_ttl', 60, 'positive PHP-range'],
    ['woocommerce_graphql_endpoint_url', 'graphql', 'at least two segments'],
    ['woocommerce_graphql_endpoint_url', '/wc/graphql', 'native normalized segment grammar'],
    ['woocommerce_graphql_endpoint_url', 'wc//graphql', 'native normalized segment grammar'],
    ['woocommerce_graphql_endpoint_url', 'wc/গ্রাফ', 'native normalized segment grammar'],
    ['woocommerce_gateway_order', [0], 'named map'],
    ['woocommerce_gateway_order', ['cod' => 0, 'bacs' => 0], 'unique bounded integer positions'],
    ['woocommerce_gateway_order', ['bad key' => 0], 'unique bounded integer positions'],
    ['woocommerce_gateway_order', ['cod' => '0'], 'unique bounded integer positions'],
    ['woocommerce_pickup_location_settings', ['unknown' => 'secret'], 'permit only enabled, title, tax_status, and cost'],
    ['woocommerce_pickup_location_settings', [
        'enabled' => true,
        'title' => 'Pickup',
        'tax_status' => 'taxable',
        'cost' => '',
    ], 'enabled must be exact yes or no'],
    ['woocommerce_pickup_location_settings', [
        'enabled' => 'yes',
        'title' => '<b>Pickup</b>',
        'tax_status' => 'taxable',
        'cost' => '',
    ], 'native WordPress text-sanitized bytes'],
    ['woocommerce_pickup_location_settings', [
        'enabled' => 'yes',
        'title' => 'Pickup',
        'tax_status' => 'inherit',
        'cost' => '',
    ], 'tax_status must be exact taxable or none'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '1.0e2',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '12 USD',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '+12.50',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '12.',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '12,50',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '--12.50',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '1.2.3',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => ' 12.50',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => '12.50 ',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => 'INF',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => 'NaN',
    ], 'native decimal calculation bytes'],
    ['woocommerce_pickup_location_settings', [
        'cost' => str_repeat('9', 1025),
    ], 'valid UTF-8 without controls and at most 1024 bytes'],
    ['pickup_location_pickup_locations', [[
        'name' => 'Depot',
        'address' => [
            'address_1' => '1 Main St',
            'city' => 'City',
            'state' => 'ST',
            'postcode' => '12345',
            'country' => 'US',
        ],
        'details' => '',
        'enabled' => 'yes',
    ]], 'enabled must be a native REST boolean'],
    ['pickup_location_pickup_locations', [[
        'name' => 'Depot',
        'address' => [
            'address_1' => '1 Main St',
            'city' => 'City',
            'state' => 'ST',
            'postcode' => '12345',
            'country' => 'US',
            'county' => 'secret-county',
        ],
        'details' => '',
        'enabled' => true,
    ]], 'address permits only'],
    ['pickup_location_pickup_locations', [[
        'name' => 'Depot',
        'address' => [
            'address_1' => '1 Main St',
            'city' => 'City',
            'state' => 'ST',
            'postcode' => '12345',
            'country' => 'US',
        ],
        'details' => '<script>bad</script>',
        'enabled' => true,
    ]], 'HTML-sanitized bytes'],
    ['woocommerce_checkout_terms_and_conditions_checkbox_text', '<script>bad</script>', 'HTML-sanitized bytes'],
    ['woocommerce_demo_store_notice', '<script>hidden()</script><strong>Sale</strong>', 'HTML-sanitized bytes'],
    ['woocommerce_demo_store_notice', str_repeat('x', 262145), 'at most 262144 bytes'],
    ['woocommerce_thumbnail_cropping', 'square', 'exact native value set'],
    ['woocommerce_thumbnail_cropping', true, 'exact native value set'],
    ['woocommerce_single_image_width', 600, 'canonical native absint string'],
    ['woocommerce_single_image_width', '-1', 'canonical native absint string'],
    ['woocommerce_single_image_width', '01', 'canonical native absint string'],
    ['woocommerce_single_image_width', '1.5', 'canonical native absint string'],
    ['woocommerce_single_image_width', '1e3', 'canonical native absint string'],
    ['woocommerce_single_image_width', ' 600', 'canonical native absint string'],
    ['woocommerce_single_image_width', '32769', 'canonical native absint string'],
    ['woocommerce_thumbnail_image_width', '9223372036854775808', 'canonical native absint string'],
    ['woocommerce_thumbnail_cropping_custom_width', '1001', 'canonical native absint string'],
    ['woocommerce_thumbnail_cropping_custom_height', [], 'canonical native absint string'],
];
foreach ($invalidSettings as [$name, $value, $fragment]) {
    $diagnostics = woo_readiness_option_diagnostics($interpreter, [
        $name => ['state' => 'present', 'value' => $value],
    ]);
    duo_check(
        $diagnostics !== []
            && str_contains(implode(' | ', woo_readiness_messages($diagnostics)), $fragment),
        "$name rejects malformed repository state at its exact native boundary: $fragment"
    );
}

$tooManyPickupLocations = array_fill(0, 257, $validPickupLocations[0]);
$tooManyPickupDiagnostics = woo_readiness_option_diagnostics($interpreter, [
    'pickup_location_pickup_locations' => ['state' => 'present', 'value' => $tooManyPickupLocations],
]);
duo_check(
    str_contains(implode(' | ', woo_readiness_messages($tooManyPickupDiagnostics)), 'at most 256'),
    'local-pickup inventory refuses before accepting more than 256 locations'
);
$oversizedPickup = $validPickupLocations[0];
$oversizedPickup['name'] = str_repeat('x', 4096);
$oversizedPickup['details'] = str_repeat('y', 16384);
foreach (array_keys($oversizedPickup['address']) as $addressField) {
    $oversizedPickup['address'][$addressField] = str_repeat('z', 4096);
}
$oversizedPickupDiagnostics = woo_readiness_option_diagnostics($interpreter, [
    'pickup_location_pickup_locations' => [
        'state' => 'present',
        'value' => array_fill(0, 32, $oversizedPickup),
    ],
]);
duo_check(
    str_contains(implode(' | ', woo_readiness_messages($oversizedPickupDiagnostics)), 'one-megabyte aggregate'),
    'local-pickup inventory enforces its aggregate decoded-byte bound before field traversal'
);

$statusFlood = array_fill(0, 129, 'processing');
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages(woo_readiness_option_diagnostics($interpreter, [
            'woocommerce_actionable_order_statuses' => ['state' => 'present', 'value' => $statusFlood],
        ]))),
        'at most 128'
    ),
    'order-status settings refuse before accepting an unbounded decoded list'
);
$settingsSecret = 'settings_secret_marker_DO_NOT_ECHO';
$settingsSecretDiagnostics = woo_readiness_option_diagnostics($interpreter, [
    'woocommerce_graphql_endpoint_url' => ['state' => 'present', 'value' => "wc/$settingsSecret!"],
]);
duo_check(
    $settingsSecretDiagnostics !== []
        && !str_contains(implode(' | ', woo_readiness_messages($settingsSecretDiagnostics)), $settingsSecret),
    'settings schema refusals identify only the option and shape, never merchant bytes'
);
$oversizedThumbnailProjection = woo_readiness_option_diagnostics($interpreter, [
    'woocommerce_thumbnail_cropping' => ['state' => 'present', 'value' => 'custom'],
    'woocommerce_thumbnail_cropping_custom_height' => ['state' => 'present', 'value' => '1000'],
    'woocommerce_thumbnail_cropping_custom_width' => ['state' => 'present', 'value' => '1'],
    'woocommerce_thumbnail_image_width' => ['state' => 'present', 'value' => '32768'],
]);
duo_check(
    str_contains(
        implode(' | ', woo_readiness_messages($oversizedThumbnailProjection)),
        'would exceed the 32768-pixel derived image boundary'
    ),
    'custom thumbnail settings refuse a repository projection that would exceed the bounded native image dimension'
);
$thumbnailSecret = 'thumbnail_secret_marker_DO_NOT_ECHO';
$thumbnailSecretDiagnostics = woo_readiness_option_diagnostics($interpreter, [
    'woocommerce_thumbnail_image_width' => ['state' => 'present', 'value' => "500$thumbnailSecret"],
]);
duo_check(
    $thumbnailSecretDiagnostics !== []
        && !str_contains(implode(' | ', woo_readiness_messages($thumbnailSecretDiagnostics)), $thumbnailSecret),
    'thumbnail option refusal identifies only the option and bounded grammar, never hostile merchant bytes'
);
duo_check(
    $GLOBALS['wooReadinessNativeTextCalls'] !== []
        && $GLOBALS['wooReadinessNativeHtmlCalls'] !== []
        && !function_exists('wc_format_decimal'),
    'settings readiness executes native WordPress sanitizers while decimal validation remains compile-safe with Woo inactive'
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

$root = dirname(__DIR__, 4);
$woocommerceReadiness = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/evidence/production-readiness.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$scopeEvidence = $woocommerceReadiness['covered']['scope-platform'] ?? [];
duo_check(
    in_array('adapter-packages/woocommerce/tests/live/regress_woocommerce_multisite_refusal.sh', $scopeEvidence, true),
    'WooCommerce readiness binds scope-platform to the exact populated multisite refusal suite'
);
$multisiteRefusal = (string) file_get_contents(
    dirname(__DIR__) . '/live/regress_woocommerce_multisite_refusal.sh'
);
foreach ([
    'DUO_EXPECTED_SOURCE_SHA',
    'validate_artifact_library',
    'fetch_artifact woocommerce 11.0.1 cli1 plugin',
    'woo_plugin_identity',
    'establish_woocommerce_hpos wp1',
    'woo_plugin_tree_hash',
    'woocommerce_custom_orders_table_enabled',
    'woo_storage_fingerprint',
    'duo_tables',
    'duo_options',
    'repo_git_fingerprint',
    'duo_woocommerce_multisite_canary',
    'wp1 core multisite-convert',
    'for command in capture plan deploy apply; do',
    'reason_code == "multisite_unsupported"',
    'state.capture-staging',
    'site.duo.json',
    'GREEN=0',
] as $multisiteEvidence) {
    duo_check(
        str_contains($multisiteRefusal, $multisiteEvidence),
        "WooCommerce multisite refusal statically binds $multisiteEvidence"
    );
}

$lifecycleEvidence = $woocommerceReadiness['covered']['lifecycle'] ?? [];
duo_check(
    in_array('adapter-packages/woocommerce/fixtures/woocommerce-destructive-lifecycle.sh', $lifecycleEvidence, true),
    'WooCommerce readiness binds lifecycle to the explicit destructive-uninstall recovery fragment'
);
$checksScript = (string) file_get_contents(dirname(__DIR__) . '/conformance/check.sh');
foreach ([
    'DUO_TEST_PROMOTION_PAUSE_MS=30000',
    'process_fence_held',
    'woocommerce_provider_guard',
    'provider:woocommerce-product-lookups/rebuild_product_lookups',
    'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
    'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes',
    'wc_product_attributes_lookup',
    'wc_product_download_directories',
    'actionscheduler_actions',
    'CONCURRENT_PAUSE_OBSERVED_AT',
    'left the deterministic pause during the loser mutation guard',
    'WooCommerce provider race zero-action retry',
    'fixtures/woocommerce-destructive-lifecycle.sh',
    'check_woocommerce_destructive_lifecycle',
] as $conformanceEvidence) {
    duo_check(
        str_contains($checksScript, $conformanceEvidence),
        "WooCommerce exact conformance statically binds $conformanceEvidence"
    );
}

$destructiveLifecycle = (string) file_get_contents(
    dirname(__DIR__, 2) . '/fixtures/woocommerce-destructive-lifecycle.sh'
);
foreach ([
    'WC_REMOVE_ALL_DATA',
    'duo identity-export --repo=/siterepo',
    'db export /siterepo/.tmp-woocommerce-remove-all.sql --add-drop-table',
    'canonical mapped identity|refusing to (create|infer|rebind)',
    'plan minted or rewrote identities before refusal',
    'identity sidecar witness mismatch',
    'mapped identity row [A-Za-z0-9_]+:[0-9]+ is missing',
    'db import /siterepo/.tmp-woocommerce-remove-all.sql',
    'database recovery did not restore the exact catalog/options/lookup preimage',
    '(.actions | length) == 0',
    'did not recapture byte-identically',
] as $destructiveEvidence) {
    duo_check(
        str_contains($destructiveLifecycle, $destructiveEvidence),
        "WooCommerce destructive lifecycle statically binds $destructiveEvidence"
    );
}
$canonicalMapGuard = (string) file_get_contents(
    $root . '/agent/src/Repository/CanonicalLedgerMapGuard.php'
);
$snapshotService = (string) file_get_contents(
    $root . '/agent/src/Capture/CaptureSnapshotService.php'
);
$canonicalRefusal = (string) file_get_contents(
    $root . '/agent/src/Kernel/CommandRefusal.php'
);
foreach ([
    'CanonicalMapWitness::assert_exact(',
    'count($mappedRows) !== count($kinds)',
    'canonicalIdentityRecoveryRequired($failure)',
] as $guardEvidence) {
    duo_check(
        str_contains($canonicalMapGuard, $guardEvidence),
        "WooCommerce destructive recovery guard statically binds $guardEvidence"
    );
}
duo_check(
    substr_count($snapshotService, 'CanonicalLedgerMapGuard::assert_pre_prune($policy, $repository)') === 2
        && strpos($snapshotService, 'CanonicalLedgerMapGuard::assert_pre_prune($policy, $repository)')
            < strpos($snapshotService, 'Ledger::prune_dead_map()')
        && str_contains($canonicalRefusal, "'canonical_identity_recovery_required'")
        && str_contains($canonicalRefusal, 'refusing to create or rebind it'),
    'ordinary and strict plan observations preserve the stable pre-prune canonical recovery refusal'
);

$matrixDriver = (string) file_get_contents(
    $root . '/sandbox/tests/certify/certify_version_matrix.sh'
);
$woocommerceMatrix = (string) file_get_contents(
    dirname(__DIR__) . '/certify/version-matrix.sh'
);
duo_check(
    str_contains($woocommerceMatrix, 'check_woocommerce_in_range_downgrade "$ARTIFACT_1" "$ARTIFACT_2"'),
    'WooCommerce version matrix invokes the in-range downgrade with both exact retained artifacts'
);
foreach ([
    'check_woocommerce_in_range_downgrade',
    '.code_drift[0].installed_version == "11.0.0"',
    '.code_drift[0].recorded_version == "11.0.1"',
    'woocommerce_boundary_storage_hash',
    'FORCED past code_drift',
    '17.345678',
    '17.3457',
    '$package_tests/../fixtures/woocommerce-downgrade-recapture.php',
    'in-range downgrade recapture diverged outside declared derived product timestamps',
] as $downgradeEvidence) {
    duo_check(
        str_contains($woocommerceMatrix, $downgradeEvidence),
        "WooCommerce exact version matrix statically binds $downgradeEvidence"
    );
}
$downgradeComparator = (string) file_get_contents(
    dirname(__DIR__, 2) . '/fixtures/woocommerce-downgrade-recapture.php'
);
duo_check(
    str_contains($downgradeComparator, "str_starts_with(\$path, 'posts/product/')")
        && str_contains($downgradeComparator, "['conformance-widget', 'conformance-precision-download']")
        && str_contains($downgradeComparator, "['modified', 'modified_gmt']")
        && str_contains($downgradeComparator, 'source and target tree inventories differ')
        && str_contains($downgradeComparator, 'unexpected recapture difference at')
        && str_contains($downgradeComparator, 'hash_equals')
        && !str_contains($downgradeComparator, 'product_variation')
        && !str_contains($downgradeComparator, 'del(.modified'),
    'WooCommerce downgrade timestamp allowance is exact-path, raw-byte, field-presence, and tree-inventory bounded'
);

duo_check_summary('WooCommerce production readiness');
