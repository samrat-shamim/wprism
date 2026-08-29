<?php
namespace WPrism;

require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Policy/ScopeDiscovery.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/**
 * Collect-only classification walk used by `wprism pending` and adapter
 * observation. It shares the same scope and ordered metadata readers as
 * candidate capture, but owns no ledger, identity, output, or mutation path.
 */
final class CaptureGateScanner {
    private ScopeDiscovery $scopeDiscovery;
    private EntityMetaCapture $entityMetaCapture;
    private \Closure $readCheckpoint;

    public function __construct(private Policy $policy, ?callable $observationReadCheckpoint = null) {
        $this->readCheckpoint = $observationReadCheckpoint === null
            ? static function (): void {}
            : \Closure::fromCallable($observationReadCheckpoint);
        $this->scopeDiscovery = new ScopeDiscovery(
            $policy,
            $this->readCheckpoint,
            static function (string $warning): void {}
        );
        $this->entityMetaCapture = new EntityMetaCapture(
            $policy,
            new Tokens(),
            static function (string $section, string $key, $value, array $rule, string $context): void {},
            $this->readCheckpoint,
            static function (string $finding): void {}
        );
    }

    /**
     * @return array{
     *   scope:array<string,array{entities:int}>,
     *   options:array<string,array{entities:int,owner_candidates:string[],value_shapes:string[],reason:string}>,
     *   widgets:array<string,array{entities:int,value_shapes:string[],reason:string}>,
     *   post_meta:array<string,array{entities:int,post_types:string[]}>,
     *   term_meta:array<string,array{entities:int,taxonomies:string[],value_shapes:string[],reason:string}>,
     *   user_meta:array
     * }
     */
    public function scan(): array {
        $scope = $this->scopeDiscovery->gaps();

        global $wpdb;
        $options = [];
        $optionRows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name ASC",
            ARRAY_A
        ) ?: [];
        ($this->readCheckpoint)();
        $allOptionValues = [];
        foreach ($optionRows as $row) {
            $allOptionValues[(string) $row['option_name']] = (string) $row['option_value'];
        }
        foreach ($optionRows as $row) {
            $key = (string) $row['option_name'];
            $owner = $this->policy->option_namespace($key);
            if ($owner === null || $this->policy->owned_option_rule_via_interpreter($key, $allOptionValues) !== null
                || $this->policy->match_option_name_ref($key) !== null) {
                continue;
            }
            $value = PlainData::decode((string) $row['option_value'], "option $key");
            $options[$key] = [
                'entities' => 1,
                'owner_candidates' => [$owner['owner']],
                'value_shapes' => [get_debug_type($value)],
                'reason' => 'owner namespace matched but no exact or pattern classification exists',
            ];
        }

        $widgetTypes = $this->policy->widget_types();
        $widgets = [];
        foreach ($optionRows as $row) {
            $name = (string) $row['option_name'];
            if (!str_starts_with($name, 'widget_')) {
                continue;
            }
            $type = substr($name, 7);
            $value = PlainData::decode((string) $row['option_value'], "option $name");
            if (!is_array($value)) {
                $widgets[$type] = [
                    'entities' => 1,
                    'value_shapes' => [get_debug_type($value)],
                    'reason' => "widget option '$name' is not a multi-instance array",
                ];
                continue;
            }
            $instances = array_filter(
                $value,
                static fn($settings, $key): bool => (string) $key !== '_multiwidget',
                ARRAY_FILTER_USE_BOTH
            );
            if ($instances && !isset($widgetTypes[$type])) {
                $widgets[$type] = [
                    'entities' => count($instances),
                    'value_shapes' => ['multi-instance array'],
                    'reason' => 'live widget instances exist but no pinned manifest declares this widget type',
                ];
            }
        }

        $postMeta = [];
        foreach ($this->scopeDiscovery->posts() as $post) {
            $flatMeta = $this->entityMetaCapture->postMetaMap((int) $post->ID);
            foreach ($flatMeta as $key => $_) {
                if ($key === '_wp_attached_file' || $key === '_wp_attachment_image_alt') {
                    continue;
                }
                if ($this->policy->meta_rule_for_post($key, $flatMeta) !== null) {
                    continue;
                }
                $postMeta[$key]['entities'] = ($postMeta[$key]['entities'] ?? 0) + 1;
                $postMeta[$key]['post_types'][$post->post_type] = true;
            }
        }

        $termMeta = [];
        foreach ($this->scopeDiscovery->terms() as $term) {
            $flatMeta = $this->entityMetaCapture->termMetaMap((int) $term->term_id);
            foreach ($flatMeta as $key => $raw) {
                if ($this->policy->meta_rule_for_term($key, $flatMeta) !== null) {
                    continue;
                }
                $termMeta[$key]['entities'] = ($termMeta[$key]['entities'] ?? 0) + 1;
                $termMeta[$key]['taxonomies'][$term->taxonomy] = true;
                $value = PlainData::decode($raw, "term meta $key");
                $termMeta[$key]['value_shapes'][get_debug_type($value)] = true;
                $termMeta[$key]['reason'] = 'unclassified term meta on an in-scope taxonomy';
            }
        }

        $menuItemIds = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = 'nav_menu' AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'"
        ) ?: [];
        ($this->readCheckpoint)();
        foreach ($menuItemIds as $itemId) {
            $flatMeta = $this->entityMetaCapture->postMetaMap((int) $itemId);
            foreach ($flatMeta as $key => $_) {
                if ($this->policy->meta_rule_for_post($key, $flatMeta) !== null) {
                    continue;
                }
                $postMeta[$key]['entities'] = ($postMeta[$key]['entities'] ?? 0) + 1;
                $postMeta[$key]['post_types']['nav_menu_item'] = true;
            }
        }

        return [
            'scope' => $scope,
            'options' => $options,
            'widgets' => $widgets,
            'post_meta' => array_map(
                static fn($evidence) => [
                    'entities' => $evidence['entities'],
                    'post_types' => array_keys($evidence['post_types'] ?? []),
                ],
                $postMeta
            ),
            'term_meta' => array_map(
                static fn($evidence) => [
                    'entities' => $evidence['entities'],
                    'taxonomies' => array_keys($evidence['taxonomies'] ?? []),
                    'value_shapes' => array_keys($evidence['value_shapes'] ?? []),
                    'reason' => $evidence['reason'],
                ],
                $termMeta
            ),
            // Users remain environment-local and unscoped. Authored rules
            // surface value-level secret/PII refusals during real capture.
            'user_meta' => [],
        ];
    }
}
