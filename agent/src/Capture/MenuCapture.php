<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/PlainData.php';

/**
 * Read-only nav-menu discovery and canonical projection.
 *
 * This boundary owns the exact menu-term, active-theme location, and menu-item
 * reads plus item projection and same-snapshot plan observations. It never
 * mints identity or writes the ledger: Capture supplies those operations as
 * explicit callbacks and remains the sole owner of their transaction policy.
 */
final class MenuCapture {
    private object $policy;
    private object $tokens;
    private \Closure $ensureTermIdentity;
    private \Closure $ensurePostIdentity;
    private \Closure $postMetaMap;
    private \Closure $postMetaByKey;
    private \Closure $classifyMetaValue;

    public function __construct(
        object $policy,
        object $tokens,
        \Closure $ensureTermIdentity,
        \Closure $ensurePostIdentity,
        \Closure $postMetaMap,
        \Closure $postMetaByKey,
        \Closure $classifyMetaValue
    ) {
        $this->policy = $policy;
        $this->tokens = $tokens;
        $this->ensureTermIdentity = $ensureTermIdentity;
        $this->ensurePostIdentity = $ensurePostIdentity;
        $this->postMetaMap = $postMetaMap;
        $this->postMetaByKey = $postMetaByKey;
        $this->classifyMetaValue = $classifyMetaValue;
    }

    /**
     * @return array{
     *   menus:list<array{uuid:string,slug:string,front:array}>,
     *   observations:array<string,array{
     *     uuid:?string,
     *     managed_menu_item_uuids:list<string>,
     *     all_menu_item_count:int
     *   }>
     * }
     */
    public function capture(bool $mint, bool $strictReadOnly = false): array {
        global $wpdb;
        $menuTerms = $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC"
        ) ?: [];
        if (!$menuTerms) {
            return ['menus' => [], 'observations' => []];
        }

        // A plugin may classify locations derived when it rebuilds the raw
        // active-theme slot from its own state. In that posture the value is
        // not even observed, preserving Capture's historical omission.
        $locationsDerived = $this->policy->menu_field_class('locations') === 'derived';
        $locByTerm = [];
        if (!$locationsDerived) {
            $stylesheet = (string) get_option('stylesheet');
            // Avoid get_option()'s object-capable legacy unserialize path for
            // target-owned bytes; preserve its false default for absence.
            $name = 'theme_mods_' . $stylesheet;
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            ));
            $mods = $raw === null ? false : PlainData::decode($raw, "option '$name'");
            if (is_array($mods) && !empty($mods['nav_menu_locations'])) {
                foreach ($mods['nav_menu_locations'] as $loc => $tid) {
                    $locByTerm[(int) $tid][] = (string) $loc;
                }
            }
        }

        $menus = [];
        $observations = [];
        foreach ($menuTerms as $mt) {
            // One query supplies canonical published items and the plan-only
            // nested deletion facts from the same consistent snapshot.
            $allItems = $wpdb->get_results($wpdb->prepare(
                "SELECT p.*,
                        (SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                         WHERE pm.post_id = p.ID AND pm.meta_key = '_wprism_uuid'
                         ORDER BY pm.meta_id ASC LIMIT 1) AS wprism_uuid
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item'
                 ORDER BY p.menu_order ASC, p.ID ASC",
                (int) $mt->term_taxonomy_id
            )) ?: [];
            $uuid = ($this->ensureTermIdentity)($mt, 'menu', $mint, $strictReadOnly);
            if ($uuid === null) {
                // Unmanaged menus remain possible adoption targets, so retain
                // only their pre-existing item identities and total count.
                $managedItemUuids = [];
                foreach ($allItems as $ip) {
                    $itemUuid = (string) ($ip->wprism_uuid ?? '');
                    if ($itemUuid !== '') {
                        $managedItemUuids[$itemUuid] = true;
                    }
                }
                $observations[(string) (int) $mt->term_id] = [
                    'uuid' => null,
                    'managed_menu_item_uuids' => array_keys($managedItemUuids),
                    'all_menu_item_count' => count($allItems),
                ];
                continue;
            }
            $items = array_values(array_filter(
                $allItems,
                static fn(object $item): bool => (string) ($item->post_status ?? '') === 'publish'
            ));

            // Identity first: parent links may point to a later published
            // item, so projection starts only after the whole pass completes.
            $itemUuidById = [];
            foreach ($items as $ip) {
                $iu = ($this->ensurePostIdentity)(
                    (int) $ip->ID,
                    'menu_item',
                    $mint,
                    $strictReadOnly
                );
                if ($iu !== null) {
                    $itemUuidById[(int) $ip->ID] = $iu;
                }
            }
            $managedItemUuids = [];
            foreach ($allItems as $ip) {
                $id = (int) $ip->ID;
                $itemUuid = $itemUuidById[$id] ?? (string) ($ip->wprism_uuid ?? '');
                if ($itemUuid !== '') {
                    $managedItemUuids[$itemUuid] = true;
                }
            }
            $observations[(string) (int) $mt->term_id] = [
                'uuid' => $uuid,
                'managed_menu_item_uuids' => array_keys($managedItemUuids),
                'all_menu_item_count' => count($allItems),
            ];

            $itemList = [];
            foreach ($items as $ip) {
                $iid = (int) $ip->ID;
                $iu = $itemUuidById[$iid] ?? null;
                if ($iu === null) {
                    continue;
                }
                $m = ($this->postMetaMap)($iid);
                $type = $m['_menu_item_type'] ?? 'custom';
                $objectId = (int) ($m['_menu_item_object_id'] ?? 0);
                if ($type === 'post_type') {
                    $ref = $this->tokens->id_to_token($objectId, 'post')
                        ?? throw new \RuntimeException(
                            "wprism: menu '{$mt->slug}' item $iid points at unmanaged post $objectId"
                        );
                } elseif ($type === 'taxonomy') {
                    $ref = $this->tokens->id_to_token($objectId, 'term')
                        ?? throw new \RuntimeException(
                            "wprism: menu '{$mt->slug}' item $iid points at unmanaged term $objectId"
                        );
                } else {
                    $ref = $this->tokens->tokenize_text((string) ($m['_menu_item_url'] ?? ''));
                }
                $parentItem = (int) ($m['_menu_item_menu_item_parent'] ?? 0);
                $classes = PlainData::decode(
                    $m['_menu_item_classes'] ?? '',
                    "menu '{$mt->slug}' item $iid _menu_item_classes"
                );
                $classes = is_array($classes)
                    ? array_values(array_filter(array_map('strval', $classes), fn($s) => $s !== ''))
                    : [];

                // Core structural keys classify managed/runtime. Every other
                // key reaches the same shared post-meta loud/authored gate.
                $itemByKey = ($this->postMetaByKey)($iid);
                $itemFlatMeta = array_map(fn($vals) => $vals[0], $itemByKey);
                $itemMeta = [];
                foreach ($itemByKey as $ikey => $ivalues) {
                    [$store, $iv] = ($this->classifyMetaValue)(
                        $ikey,
                        $ivalues,
                        $itemFlatMeta,
                        "menu '{$mt->slug}' item $iid",
                        'menu_item_meta'
                    );
                    if ($store) {
                        $itemMeta[$ikey] = $iv;
                    }
                }
                $itemList[] = [
                    'uuid' => $iu,
                    'type' => $type,
                    'object' => (string) ($m['_menu_item_object'] ?? ''),
                    'ref' => $ref,
                    'meta' => $itemMeta,
                    'parent' => $parentItem > 0 ? ($itemUuidById[$parentItem] ?? null) : null,
                    'position' => (int) $ip->menu_order,
                    'title' => $ip->post_title,
                    'description' => $this->tokens->tokenize_text((string) $ip->post_content),
                    'attr_title' => (string) $ip->post_excerpt,
                    'target' => (string) ($m['_menu_item_target'] ?? ''),
                    'classes' => $classes,
                    'xfn' => (string) ($m['_menu_item_xfn'] ?? ''),
                ];
            }

            $front = [
                'uuid' => $uuid,
                'name' => $mt->name,
                'slug' => $mt->slug,
                'items' => $itemList,
            ];
            if (!$locationsDerived) {
                $locations = $locByTerm[(int) $mt->term_id] ?? [];
                sort($locations, SORT_STRING);
                $front['locations'] = $locations;
            }
            $menus[] = [
                'uuid' => $uuid,
                'slug' => $mt->slug,
                'front' => $front,
            ];
        }
        return ['menus' => $menus, 'observations' => $observations];
    }
}
