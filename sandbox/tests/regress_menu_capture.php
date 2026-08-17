<?php
/** Direct offline characterization for the extracted nav-menu capture boundary. */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['menu_capture_stylesheet'] = 'fixture-theme';
$GLOBALS['menu_capture_option_calls'] = [];
function get_option($name, $default = false) {
    $GLOBALS['menu_capture_option_calls'][] = (string) $name;
    return $name === 'stylesheet' ? $GLOBALS['menu_capture_stylesheet'] : $default;
}

final class MenuCaptureFakeWpdb {
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    /** @var list<object> */
    public array $menuTerms = [];
    /** @var array<int,list<object>> */
    public array $itemsByTermTaxonomy = [];
    /** @var array<string,string> */
    public array $optionValues = [];
    /** @var list<array{method:string,sql:string,args:array}> */
    public array $queries = [];

    public function prepare($sql, ...$args): array {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return ['sql' => (string) $sql, 'args' => $args];
    }

    public function get_results($query, $output = ARRAY_A): array {
        [$sql, $args] = $this->unwrap($query);
        $this->queries[] = ['method' => 'get_results', 'sql' => self::normalize($sql), 'args' => $args];
        if (str_contains($sql, "WHERE tt.taxonomy = 'nav_menu'")) {
            return $this->menuTerms;
        }
        if (str_contains($sql, "p.post_type = 'nav_menu_item'")) {
            return $this->itemsByTermTaxonomy[(int) ($args[0] ?? 0)] ?? [];
        }
        throw new RuntimeException('unexpected menu-capture get_results query: ' . self::normalize($sql));
    }

    public function get_var($query) {
        [$sql, $args] = $this->unwrap($query);
        $this->queries[] = ['method' => 'get_var', 'sql' => self::normalize($sql), 'args' => $args];
        if (str_contains($sql, 'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1')) {
            return $this->optionValues[(string) ($args[0] ?? '')] ?? null;
        }
        throw new RuntimeException('unexpected menu-capture get_var query: ' . self::normalize($sql));
    }

    private function unwrap($query): array {
        return is_array($query) && isset($query['sql'])
            ? [(string) $query['sql'], $query['args'] ?? []]
            : [(string) $query, []];
    }

    private static function normalize(string $sql): string {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}

final class MenuCaptureFakePolicy {
    public string $locationsClass = 'authored';
    /** @var list<string> */
    public array $calls = [];

    public function menu_field_class(string $field): string {
        $this->calls[] = $field;
        return $this->locationsClass;
    }
}

final class MenuCaptureFakeTokens {
    /** @var array<string,array<int,string>> */
    public array $refs = [
        'post' => [9 => '{{post:post-nine}}'],
        'term' => [5 => '{{term:term-five}}'],
    ];
    /** @var list<array{0:string,1:int}> */
    public array $idCalls = [];
    /** @var list<string> */
    public array $textCalls = [];

    public function id_to_token(int $id, string $kind): ?string {
        $this->idCalls[] = [$kind, $id];
        return $this->refs[$kind][$id] ?? null;
    }

    public function tokenize_text(string $value): string {
        $this->textCalls[] = $value;
        return 'text:' . $value;
    }
}

final class MenuCaptureWakeupProbe {
    public static bool $woke = false;
    public function __wakeup(): void { self::$woke = true; }
}

function menu_item(int $id, string $status, int $position, string $uuid, string $title): object {
    return (object) [
        'ID' => $id,
        'post_status' => $status,
        'menu_order' => $position,
        'post_title' => $title,
        'post_content' => "$title body",
        'post_excerpt' => "$title attr",
        'duo_uuid' => $uuid,
    ];
}

$root = dirname(__DIR__, 2);
require_once "$root/agent/src/Capture/MenuCapture.php";

use Duo\MenuCapture;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

$check(class_exists(MenuCapture::class, false), 'MenuCapture loads as a direct offline boundary');
$check(!class_exists(Duo\Capture::class, false), 'MenuCapture does not load Duo\\Capture');
$check(!class_exists(Duo\Policy::class, false), 'MenuCapture does not load Duo\\Policy');
$check(!class_exists(Duo\Tokens::class, false), 'MenuCapture does not load Duo\\Tokens');
$check(!class_exists(Duo\Ledger::class, false), 'MenuCapture does not load Duo\\Ledger');

$wpdb = new MenuCaptureFakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->menuTerms = [
    (object) [
        'term_id' => 10,
        'name' => 'Primary Menu',
        'slug' => 'primary-menu',
        'term_taxonomy_id' => 100,
        'taxonomy' => 'nav_menu',
        'description' => '',
        'parent' => 0,
    ],
    (object) [
        'term_id' => 20,
        'name' => 'Unmanaged Menu',
        'slug' => 'unmanaged-menu',
        'term_taxonomy_id' => 200,
        'taxonomy' => 'nav_menu',
        'description' => '',
        'parent' => 0,
    ],
];
$wpdb->itemsByTermTaxonomy = [
    100 => [
        menu_item(100, 'publish', 1, 'persisted-item-100', 'Custom'),
        menu_item(101, 'publish', 2, '', 'Post'),
        menu_item(102, 'publish', 3, '', 'Term'),
        menu_item(103, 'draft', 4, 'draft-existing', 'Draft'),
        menu_item(104, 'publish', 5, '', 'Unmapped'),
    ],
    200 => [menu_item(200, 'publish', 1, 'legacy-unmanaged-item', 'Legacy')],
];
$wpdb->optionValues['theme_mods_fixture-theme'] = serialize([
    'nav_menu_locations' => ['primary' => 10, 'footer' => 10, 'unused' => 20],
]);

$flatMeta = [
    100 => [
        '_menu_item_type' => 'custom',
        '_menu_item_object' => 'custom',
        '_menu_item_object_id' => '0',
        '_menu_item_url' => 'https://source.test/?p=9',
        '_menu_item_menu_item_parent' => '0',
        '_menu_item_target' => '_blank',
        '_menu_item_classes' => serialize(['cta', '', 7]),
        '_menu_item_xfn' => 'friend',
        '_plugin_icon_post' => '9',
    ],
    101 => [
        '_menu_item_type' => 'post_type',
        '_menu_item_object' => 'post',
        '_menu_item_object_id' => '9',
        '_menu_item_menu_item_parent' => '100',
        '_menu_item_classes' => serialize([]),
    ],
    102 => [
        '_menu_item_type' => 'taxonomy',
        '_menu_item_object' => 'category',
        '_menu_item_object_id' => '5',
        '_menu_item_menu_item_parent' => '999',
        '_menu_item_classes' => 'ordinary scalar',
    ],
];
$byKeyMeta = [];
foreach ($flatMeta as $id => $map) {
    foreach ($map as $key => $value) {
        $byKeyMeta[$id][$key] = [$value];
    }
}

$policy = new MenuCaptureFakePolicy();
$tokens = new MenuCaptureFakeTokens();
$termIdentityCalls = [];
$postIdentityCalls = [];
$flatCalls = [];
$byKeyCalls = [];
$classificationCalls = [];
$termIdentities = [10 => 'menu-ten'];
$postIdentities = [100 => 'item-100', 101 => 'item-101', 102 => 'item-102'];

$capture = new MenuCapture(
    $policy,
    $tokens,
    static function (object $term, string $type, bool $mint, bool $strict) use (
        &$termIdentityCalls,
        &$termIdentities
    ): ?string {
        $termIdentityCalls[] = [(int) $term->term_id, $type, $mint, $strict];
        return $termIdentities[(int) $term->term_id] ?? null;
    },
    static function (int $id, string $type, bool $mint, bool $strict) use (
        &$postIdentityCalls,
        &$postIdentities
    ): ?string {
        $postIdentityCalls[] = [$id, $type, $mint, $strict];
        return $postIdentities[$id] ?? null;
    },
    static function (int $id) use (&$flatCalls, &$flatMeta): array {
        $flatCalls[] = $id;
        return $flatMeta[$id] ?? [];
    },
    static function (int $id) use (&$byKeyCalls, &$byKeyMeta): array {
        $byKeyCalls[] = $id;
        return $byKeyMeta[$id] ?? [];
    },
    static function (
        string $key,
        array $values,
        array $siblings,
        string $owner,
        string $prefix
    ) use (&$classificationCalls): array {
        $classificationCalls[] = [$key, $values, $siblings, $owner, $prefix];
        return $key === '_plugin_icon_post' ? [true, '{{post:portable-plugin-value}}'] : [false, null];
    }
);

$result = $capture->capture(true, true);
$check(count($result['menus']) === 1, 'only the managed menu enters canonical capture state');
$menu = $result['menus'][0] ?? [];
$front = $menu['front'] ?? [];
$check(
    ($menu['uuid'] ?? null) === 'menu-ten'
        && ($menu['slug'] ?? null) === 'primary-menu'
        && ($front['name'] ?? null) === 'Primary Menu',
    'menu identity and canonical front fields are preserved'
);
$check(($front['locations'] ?? null) === ['footer', 'primary'], 'authored locations sort deterministically');
$items = [];
foreach (($front['items'] ?? []) as $item) {
    $items[$item['uuid']] = $item;
}
$check(array_keys($items) === ['item-100', 'item-101', 'item-102'],
    'published mapped items retain query order while draft and unmapped items stay out');
$check(
    ($items['item-100']['ref'] ?? null) === 'text:https://source.test/?p=9'
        && ($items['item-100']['classes'] ?? null) === ['cta', '7']
        && ($items['item-100']['meta']['_plugin_icon_post'] ?? null) === '{{post:portable-plugin-value}}',
    'custom URL, classes, and authored plugin meta retain their exact projection semantics'
);
$check(
    ($items['item-101']['ref'] ?? null) === '{{post:post-nine}}'
        && ($items['item-101']['parent'] ?? null) === 'item-100',
    'post-target references and parent-item references resolve through the injected identity/token maps'
);
$check(
    ($items['item-102']['ref'] ?? null) === '{{term:term-five}}'
        && array_key_exists('parent', $items['item-102'])
        && $items['item-102']['parent'] === null,
    'taxonomy references resolve while an unmanaged parent remains the historical null value'
);
$check(
    $result['observations'] === [
        '10' => [
            'uuid' => 'menu-ten',
            'managed_menu_item_uuids' => ['item-100', 'item-101', 'item-102', 'draft-existing'],
            'all_menu_item_count' => 5,
        ],
        '20' => [
            'uuid' => null,
            'managed_menu_item_uuids' => ['legacy-unmanaged-item'],
            'all_menu_item_count' => 1,
        ],
    ],
    'same-snapshot plan observations retain managed draft identities and unmanaged-menu counts'
);
$check(
    $termIdentityCalls === [[10, 'menu', true, true], [20, 'menu', true, true]],
    'menu identity requests preserve term order plus exact mint/strict flags'
);
$check(
    array_column($postIdentityCalls, 0) === [100, 101, 102, 104]
        && count(array_filter($postIdentityCalls, static fn(array $call): bool => $call[1] === 'menu_item'
            && $call[2] === true && $call[3] === true)) === 4,
    'item identity first pass covers only published items of a managed menu and forwards exact flags'
);
$check($flatCalls === [100, 101, 102] && $byKeyCalls === [100, 101, 102],
    'metadata reads occur only for published items that received canonical identity');
$pluginCalls = array_values(array_filter(
    $classificationCalls,
    static fn(array $call): bool => $call[0] === '_plugin_icon_post'
));
$check(
    count($pluginCalls) === 1
        && $pluginCalls[0][2]['_plugin_icon_post'] === '9'
        && $pluginCalls[0][3] === "menu 'primary-menu' item 100"
        && $pluginCalls[0][4] === 'menu_item_meta',
    'every item key reaches the shared classifier with first-value sibling context and loud-gate prefix'
);

$normalizedMenuSql = 'SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent'
    . ' FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id'
    . " WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC";
$normalizedItemSql = 'SELECT p.*, (SELECT pm.meta_value FROM wp_postmeta pm'
    . " WHERE pm.post_id = p.ID AND pm.meta_key = '_duo_uuid' ORDER BY pm.meta_id ASC LIMIT 1) AS duo_uuid"
    . ' FROM wp_posts p JOIN wp_term_relationships tr ON tr.object_id = p.ID'
    . " WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item'"
    . ' ORDER BY p.menu_order ASC, p.ID ASC';
$check(
    ($wpdb->queries[0]['sql'] ?? null) === $normalizedMenuSql
        && ($wpdb->queries[1]['sql'] ?? null) === 'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1'
        && ($wpdb->queries[1]['args'] ?? null) === ['theme_mods_fixture-theme']
        && ($wpdb->queries[2]['sql'] ?? null) === $normalizedItemSql
        && ($wpdb->queries[2]['args'] ?? null) === [100]
        && ($wpdb->queries[3]['sql'] ?? null) === $normalizedItemSql
        && ($wpdb->queries[3]['args'] ?? null) === [200],
    'menu, raw theme-mod, and per-menu item reads preserve exact SQL and deterministic ordering'
);

$policy->locationsClass = 'derived';
$wpdb->queries = [];
$GLOBALS['menu_capture_option_calls'] = [];
$derived = $capture->capture(false, false);
$check(
    !array_key_exists('locations', $derived['menus'][0]['front'])
        && $GLOBALS['menu_capture_option_calls'] === []
        && count(array_filter($wpdb->queries, static fn(array $q): bool => $q['method'] === 'get_var')) === 0,
    'derived locations omit the field and skip both stylesheet and raw theme-mod observations'
);

$policy->locationsClass = 'authored';
$wpdb->optionValues['theme_mods_fixture-theme'] = serialize(new MenuCaptureWakeupProbe());
MenuCaptureWakeupProbe::$woke = false;
$objectRejected = false;
try {
    $capture->capture(false, false);
} catch (Throwable $e) {
    $objectRejected = str_contains($e->getMessage(), 'PHP object');
}
$check($objectRejected && !MenuCaptureWakeupProbe::$woke,
    'raw theme-mod decoding rejects serialized objects without invoking __wakeup');
$wpdb->optionValues['theme_mods_fixture-theme'] = serialize([
    'nav_menu_locations' => ['primary' => 10],
]);

unset($tokens->refs['post'][9]);
$postRefRejected = false;
try {
    $capture->capture(false, false);
} catch (Throwable $e) {
    $postRefRejected = $e->getMessage() === "duo: menu 'primary-menu' item 101 points at unmanaged post 9";
}
$check($postRefRejected, 'a menu-item post target outside canonical identity still refuses exactly');
$tokens->refs['post'][9] = '{{post:post-nine}}';
unset($tokens->refs['term'][5]);
$termRefRejected = false;
try {
    $capture->capture(false, false);
} catch (Throwable $e) {
    $termRefRejected = $e->getMessage() === "duo: menu 'primary-menu' item 102 points at unmanaged term 5";
}
$check($termRefRejected, 'a menu-item taxonomy target outside canonical identity still refuses exactly');
$tokens->refs['term'][5] = '{{term:term-five}}';

$wpdb->menuTerms = [];
$wpdb->queries = [];
$GLOBALS['menu_capture_option_calls'] = [];
$empty = $capture->capture(false, false);
$check(
    $empty === ['menus' => [], 'observations' => []]
        && $GLOBALS['menu_capture_option_calls'] === []
        && count($wpdb->queries) === 1,
    'an empty menu roster returns before stylesheet, item, identity, or metadata work'
);

$menuSource = file_get_contents("$root/agent/src/Capture/MenuCapture.php");
$captureSource = file_get_contents("$root/agent/src/Capture/Capture.php");
$workflowSource = file_get_contents("$root/agent/src/Capture/CapturePublicationWorkflow.php");
$candidateSource = file_get_contents("$root/agent/src/Capture/CaptureCandidateBuilder.php");
$check(
    !str_contains($menuSource, 'Ledger::')
        && !str_contains($menuSource, 'Db::')
        && !str_contains($menuSource, 'Uuid::'),
    'MenuCapture owns no identity or ledger mutation implementation'
);
$check(
    str_contains($captureSource, "require_once __DIR__ . '/CapturePublicationWorkflow.php';")
        && str_contains($workflowSource, "require_once __DIR__ . '/CaptureCandidateBuilder.php';")
        && str_contains($workflowSource, '$c = new CaptureCandidateBuilder(')
        && str_contains($candidateSource, "require_once __DIR__ . '/MenuCapture.php';")
        && str_contains($candidateSource, 'private MenuCapture $menuCapture;')
        && str_contains($candidateSource, '$this->menuCapture = new MenuCapture('),
    'Capture delegates candidate assembly and the builder binds the extracted menu collaborator'
);
$check(
    str_contains($candidateSource, '$result = $this->menuCapture->capture($mint, $strictReadOnly);')
        && str_contains($candidateSource, '$this->planObservations[\'menus_by_term_id\'] = $result[\'observations\'];')
        && str_contains($candidateSource, 'return $result[\'menus\'];'),
    'candidate builder retains the direct menu seam and same-snapshot observation side effect'
);
$check(
    !str_contains($captureSource, '(SELECT pm.meta_value FROM {$wpdb->postmeta} pm'),
    'Capture no longer owns the duplicate menu-item roster implementation'
);

if ($failures > 0) {
    echo "\nFAIL: $failures menu-capture check(s) failed\n";
    exit(1);
}
echo "\nREGRESS_MENU_CAPTURE PASSED\n";
