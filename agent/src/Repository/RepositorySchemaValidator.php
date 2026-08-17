<?php
namespace Duo;

// Direct production loads need the real resolved policy, while the CLI
// refusal harness deliberately preloads a Policy stub. Preserve that closed
// load boundary instead of redeclaring its test double.
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}

/**
 * Validates decoded canonical entity shapes before RepositoryCompiler adds
 * identity, media, deletion, or reference semantics.
 *
 * The compiler retains parsing/routing and one aggregate diagnostic sort. An
 * injected sink therefore preserves its existing complete-error behavior.
 */
final class RepositorySchemaValidator {
    private Policy $policy;
    private string $sidebarEntityType;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, string $sidebarEntityType, \Closure $add) {
        $this->policy = $policy;
        $this->sidebarEntityType = $sidebarEntityType;
        $this->add = $add;
    }

    /** @param array<string,mixed> $data */
    public function validate(string $kind, string $path, array $data, ?string $body): void {
        $required = match ($kind) {
            'post' => ['uuid','type','slug','title','status','date','date_gmt','modified_gmt','author','parent','menu_order','comment_status','ping_status','excerpt','meta','terms'],
            'term' => ['uuid','taxonomy','name','slug','description','parent','meta','relationships'],
            // A derived menu-locations field is deliberately omitted by
            // Capture; all ordinary menus retain the historical requirement.
            'menu' => $this->policy->menu_field_class('locations') === 'derived'
                ? ['uuid','name','slug','items']
                : ['uuid','name','slug','locations','items'],
            'user-meta' => ['login','meta'],
            'sidebar' => ['widgets'],
            'table' => ['uuid','table','columns','meta'],
            default => [],
        };
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                $this->add('schema_content_mismatch', $path, $field, 'required field is missing');
            }
        }
        foreach (['uuid','slug'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                $this->add('schema_content_mismatch', $path, $field, 'field must be a string');
            }
        }
        if ($kind === 'post' && (!isset($data['meta']) || !is_array($data['meta']) || !isset($data['terms']) || !is_array($data['terms']))) {
            $this->add('schema_content_mismatch', $path, 'meta/terms', 'post meta and terms must be object maps');
        } elseif ($kind === 'post') {
            foreach ($data['terms'] as $taxonomy => $uuids) {
                if (!is_string($taxonomy) || !is_array($uuids) || !array_is_list($uuids)) {
                    $this->add('schema_content_mismatch', $path, 'terms', 'each taxonomy relationship must be a UUID list');
                    continue;
                }
                $this->validate_taxonomy_relationship_keyspace($path, "terms.$taxonomy", $taxonomy, 'post');
            }
            foreach ((array) ($data['term_orders'] ?? []) as $taxonomy => $orders) {
                if (!is_string($taxonomy) || !is_array($orders) || array_is_list($orders)) {
                    $this->add('schema_content_mismatch', $path, 'term_orders', 'each taxonomy order set must be a UUID-to-integer map');
                    continue;
                }
                foreach ($orders as $uuid => $order) {
                    if (!is_string($uuid) || !is_int($order)
                        || !in_array($uuid, (array) ($data['terms'][$taxonomy] ?? []), true)) {
                        $this->add('schema_content_mismatch', $path, 'term_orders', 'order entries must name a related UUID and carry an integer');
                    }
                }
            }
        }
        if ($kind === 'term' && (!isset($data['meta']) || !is_array($data['meta'])
            || !isset($data['relationships']) || !is_array($data['relationships']))) {
            $this->add('schema_content_mismatch', $path, 'meta/relationships', 'term meta and relationships must be object maps');
        } elseif ($kind === 'term') {
            foreach ((array) ($data['relationships'] ?? []) as $taxonomy => $uuids) {
                if (!is_string($taxonomy) || !is_array($uuids) || !array_is_list($uuids)) {
                    $this->add('schema_content_mismatch', $path, 'relationships', 'each term-object relationship must be a UUID list');
                    continue;
                }
                $this->validate_taxonomy_relationship_keyspace($path, "relationships.$taxonomy", $taxonomy, 'term');
            }
        }
        if ($kind === 'user-meta') {
            $unknown = array_values(array_diff(array_keys($data), ['login', 'meta']));
            if ($unknown) {
                sort($unknown, SORT_STRING);
                $this->add('schema_content_mismatch', $path, '', 'unknown user-meta field(s): ' . implode(', ', $unknown));
            }
            if (!is_string($data['login'] ?? null)) {
                $this->add('schema_content_mismatch', $path, 'login', 'login must be a string');
            }
            if (!isset($data['meta']) || !is_array($data['meta'])) {
                $this->add('schema_content_mismatch', $path, 'meta', 'meta must be an object map');
            }
        }
        if ($kind === 'menu' && isset($data['items']) && !is_array($data['items'])) {
            $this->add('schema_content_mismatch', $path, 'items', 'menu items must be a list');
        } elseif ($kind === 'menu') {
            foreach ((array) ($data['items'] ?? []) as $i => $item) {
                if (!is_array($item)) {
                    $this->add('schema_content_mismatch', $path, "items[$i]", 'menu item must be an object');
                    continue;
                }
                foreach (['uuid','type','ref','position','title'] as $field) {
                    if (!array_key_exists($field, $item)) {
                        $this->add('schema_content_mismatch', $path, "items[$i].$field", 'required menu-item field is missing');
                    }
                }
                if (isset($item['type']) && !in_array($item['type'], ['post_type','taxonomy','custom'], true)) {
                    $this->add('schema_content_mismatch', $path, "items[$i].type", 'menu-item type must be post_type, taxonomy, or custom');
                }
                if (isset($item['ref']) && !is_string($item['ref'])) {
                    $this->add('schema_content_mismatch', $path, "items[$i].ref", 'menu-item ref must be a string');
                }
                if (isset($item['parent']) && $item['parent'] !== null && !is_string($item['parent'])) {
                    $this->add('schema_content_mismatch', $path, "items[$i].parent", 'menu-item parent must be null or a UUID string');
                }
            }
        }
        if ($kind === 'menu' && $this->policy->menu_field_class('locations') !== 'derived') {
            if (!isset($data['locations']) || !is_array($data['locations']) || !array_is_list($data['locations'])) {
                $this->add('schema_content_mismatch', $path, 'locations', 'menu locations must be an ordered string list');
            } else {
                foreach ($data['locations'] as $i => $location) {
                    if (!is_string($location) || $location === '') {
                        $this->add('schema_content_mismatch', $path, "locations[$i]", 'menu location must be a non-empty string');
                    }
                }
            }
        }
        if ($kind === $this->sidebarEntityType) {
            $unknown = array_values(array_diff(array_keys($data), ['widgets']));
            if ($unknown) {
                sort($unknown, SORT_STRING);
                $this->add('schema_content_mismatch', $path, '', 'unknown sidebar field(s): ' . implode(', ', $unknown));
            }
            if (!isset($data['widgets']) || !is_array($data['widgets']) || !array_is_list($data['widgets'])) {
                $this->add('schema_content_mismatch', $path, 'widgets', 'widgets must be an ordered list');
            }
            $declared = $this->policy->widget_types();
            foreach ((array) ($data['widgets'] ?? []) as $i => $widget) {
                if (!is_array($widget) || array_diff(array_keys($widget), ['uuid', 'type', 'settings']) || array_diff(['uuid', 'type', 'settings'], array_keys($widget))) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i]", 'widget must contain exactly uuid, type, settings');
                    continue;
                }
                $type = (string) ($widget['type'] ?? '');
                if (!isset($declared[$type])) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i].type", "widget type '$type' is not manifest-declared");
                }
                if (!is_array($widget['settings'] ?? null)) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i].settings", 'widget settings must be an object');
                    continue;
                }
                $unknownSettings = array_diff(array_keys($widget['settings']), array_keys((array) ($declared[$type]['settings'] ?? [])));
                if ($unknownSettings) {
                    sort($unknownSettings, SORT_STRING);
                    $this->add('schema_content_mismatch', $path, "widgets[$i].settings", 'undeclared setting(s): ' . implode(', ', $unknownSettings));
                }
            }
        }
        if ($kind === 'table' && (!isset($data['columns']) || !is_array($data['columns']) || !isset($data['meta']) || !is_array($data['meta']))) {
            $this->add('schema_content_mismatch', $path, 'columns/meta', 'table columns and meta must be object maps');
        }
    }

    private function validate_taxonomy_relationship_keyspace(string $path, string $locator, string $taxonomy, string $expected): void {
        try {
            $actual = $this->policy->taxonomy_object_keyspace($taxonomy);
        } catch (\Throwable $t) {
            $this->add('taxonomy_object_keyspace_invalid', $path, $locator, $t->getMessage());
            return;
        }
        if ($actual !== $expected) {
            $this->add('taxonomy_object_keyspace_mismatch', $path, $locator, "taxonomy '$taxonomy' resolves to object_keyspace='$actual'; this field requires '$expected'");
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
