<?php
namespace Duo;

require_once __DIR__ . '/ApplyFieldMaterializer.php';

/**
 * The menu entity materializer (DUO-3347 slice 4, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles one canonical nav_menu
 * term's items and location assignment against the live target.
 *
 * Extracted from Apply.php once DUO-3347 slice 3 (ApplyFieldMaterializer) gave
 * it a non-Apply home for reconcile_authored_meta()/upsert_meta()/
 * upsert_option() — finalize_menu()/assign_locations() were otherwise fully
 * separable already (global $wpdb; static Ledger::/Db::/PlainData:: calls;
 * $this->tokens and $this->policy for detokenization and the
 * menu_field_class('locations') derived-vs-authored check), but dragging
 * those three shared, non-menu-specific helpers into a class named for one
 * entity type would have been the wrong ownership call.
 *
 * Moved verbatim; Apply keeps both methods as thin compatibility facades via
 * a lazily-constructed instance (menu_materializer()), the same pattern
 * field_materializer() and convergence_verifier() already established.
 */
final class MenuMaterializer {
    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    public function finalize_menu(array $front): void {
        global $wpdb;
        $menuTermId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $menuTt = Ledger::id_for($front['uuid'], Ledger::KIND_TT);
        Db::update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $menuTermId], null, null, 'apply update menu term');

        // existing env items by uuid
        $envItems = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS uuid FROM {$wpdb->posts} p
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_duo_uuid'
             WHERE p.post_type = 'nav_menu_item'",
            $menuTt
        ), ARRAY_A) ?: [];
        $envByUuid = [];
        foreach ($envItems as $it) {
            if (!empty($it['uuid'])) {
                $envByUuid[$it['uuid']] = (int) $it['ID'];
            }
        }

        // pass 1: ensure item rows
        $idByUuid = [];
        foreach ($front['items'] as $item) {
            $iu = $item['uuid'];
            $id = $envByUuid[$iu] ?? Ledger::id_for($iu, Ledger::KIND_POST);
            if ($id === null) {
                Db::insert($wpdb->posts, [
                    'post_author' => 0, 'post_date' => '1970-01-01 00:00:00', 'post_date_gmt' => '1970-01-01 00:00:00',
                    'post_content' => $this->tokens->detokenize_text((string) ($item['description'] ?? '')),
                    'post_title' => $item['title'], 'post_excerpt' => $item['attr_title'] ?? '',
                    'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
                    'post_password' => '', 'post_name' => $iu, 'to_ping' => '', 'pinged' => '',
                    'post_modified' => '1970-01-01 00:00:00', 'post_modified_gmt' => '1970-01-01 00:00:00',
                    'post_content_filtered' => '', 'post_parent' => 0,
                    'guid' => $this->tokens->home() . '/?duo=' . $iu,
                    'menu_order' => (int) $item['position'], 'post_type' => 'nav_menu_item',
                    'post_mime_type' => '', 'comment_count' => 0,
                ], null, 'apply insert menu item');
                $id = Db::insert_id('apply insert menu item');
                Db::insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $iu], null, 'apply insert menu-item identity');
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $id, 'term_taxonomy_id' => $menuTt, 'term_order' => 0,
                ], null, 'apply attach menu item');
            }
            Ledger::set($iu, 'menu_item', Ledger::KIND_POST, $id);
            $idByUuid[$iu] = $id;
        }

        // pass 2: fields + metas (parents resolvable now)
        foreach ($front['items'] as $item) {
            $id = $idByUuid[$item['uuid']];
            Db::update($wpdb->posts, [
                'post_title' => $item['title'],
                'post_content' => $this->tokens->detokenize_text((string) ($item['description'] ?? '')),
                'post_excerpt' => (string) ($item['attr_title'] ?? ''),
                'menu_order' => (int) $item['position'],
                'post_status' => 'publish',
            ], ['ID' => $id], null, null, 'apply update menu item');

            $objectId = 0;
            $url = '';
            if ($item['type'] === 'post_type') {
                $objectId = $this->tokens->token_to_id($item['ref']);
            } elseif ($item['type'] === 'taxonomy') {
                $objectId = $this->tokens->token_to_id($item['ref']);
            } else {
                $url = $this->tokens->detokenize_text((string) $item['ref']);
            }
            $parentId = 0;
            if (!empty($item['parent'])) {
                $parentId = $idByUuid[$item['parent']]
                    ?? throw new \RuntimeException("duo: menu {$front['slug']}: item parent {$item['parent']} not in menu");
            }
            $metas = [
                '_menu_item_type' => $item['type'],
                '_menu_item_menu_item_parent' => (string) $parentId,
                '_menu_item_object_id' => (string) ($objectId ?: $id),
                '_menu_item_object' => (string) ($item['object'] ?? ''),
                '_menu_item_target' => (string) ($item['target'] ?? ''),
                '_menu_item_classes' => serialize(array_values((array) ($item['classes'] ?? []))),
                '_menu_item_xfn' => (string) ($item['xfn'] ?? ''),
                '_menu_item_url' => $url,
            ];
            global $wpdb;
            foreach ($metas as $k => $v) {
                $this->fieldMaterializer->upsert_meta($wpdb->postmeta, 'post_id', $id, $k, $v);
            }

            // DUO-3266: any OTHER meta a manifest classifies authored on
            // this item (a plugin's own menu-item field, now captured —
            // see scope_menus()'s matching capture-side fix) reconciles
            // through the SAME ownership discipline ordinary posts use.
            // Never touches the 8 keys just written above: manifests/
            // core.json classifies them managed/runtime, never authored,
            // so reconcile_authored_meta()'s own delete-pass (which only
            // acts on rows policy calls 'authored') can't touch them, and
            // capture never puts them in item['meta'] either.
            $this->fieldMaterializer->reconcile_authored_meta($id, (array) ($item['meta'] ?? []), 'menu-item');
        }

        // remove env items no longer in the file (menu-scoped ownership)
        $keep = array_fill_keys(array_keys($idByUuid), true);
        foreach ($envByUuid as $uuid => $id) {
            if (!isset($keep[$uuid])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $menuTt], null, 'apply detach removed menu item');
                Db::delete($wpdb->postmeta, ['post_id' => $id], null, 'apply delete removed menu-item meta');
                Db::delete($wpdb->posts, ['ID' => $id], null, 'apply delete removed menu item');
                Ledger::forget($uuid);
            }
        }

        // locations in the active theme's mods. DUO-3272: skipped entirely
        // when a pinned manifest reclassifies menu_fields.locations
        // 'derived' (e.g. Polylang) -- writing here would fight the
        // plugin's own machinery (Languages::update_default()) for
        // ownership of this exact raw slot instead of leaving it alone, as
        // the reclassification promises. The menu-to-location assignment
        // this environment actually needs still applies normally, via the
        // declaring manifest's own sub_keys (Polylang: options.polylang.
        // sub_keys.nav_menus) and, if that plugin's own request lifecycle
        // does not self-heal the raw slot from it, a manifest-declared
        // rebuild action (never hardcoded Polylang knowledge here -- see
        // Apply::rebuild()).
        if ($this->policy->menu_field_class('locations') !== 'derived') {
            $this->assign_locations((int) $menuTermId, (array) ($front['locations'] ?? []));
        }
    }

    public function assign_locations(int $menuTermId, array $locations): void {
        global $wpdb;
        $name = 'theme_mods_' . (string) get_option('stylesheet');
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        $mods = $raw !== null ? PlainData::decode($raw, "option '$name'") : [];
        if (!is_array($mods)) {
            $mods = [];
        }
        $locs = (array) ($mods['nav_menu_locations'] ?? []);
        foreach ($locs as $loc => $tid) {
            if ((int) $tid === $menuTermId && !in_array((string) $loc, $locations, true)) {
                unset($locs[$loc]);
            }
        }
        foreach ($locations as $loc) {
            $locs[$loc] = $menuTermId;
        }
        $mods['nav_menu_locations'] = $locs;
        // theme_mods is a WordPress-owned helper row, not authored canonical
        // option state. WordPress itself creates this family autoloaded; keep
        // that bespoke contract explicit instead of borrowing authored-row
        // policy from state/options/core.json.
        $this->fieldMaterializer->upsert_option($name, serialize($mods), 'yes');
    }
}
