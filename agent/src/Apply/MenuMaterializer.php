<?php
namespace Duo;

require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';

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
    private const MAX_MENU_ITEMS = 10000;
    private const MAX_ITEM_RELATIONSHIPS = 10000;

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
        CacheInvalidationTransaction::assert_term_taxonomy_prepared('nav_menu', 'apply update menu term');
        $this->assert_locked_menu_identity(
            (int) $menuTermId,
            (int) $menuTt,
            (string) $front['slug']
        );

        // The menu relationship index range, every referenced post row and
        // each postmeta owner range are locked before the complete-set diff.
        // Otherwise a concurrent editor can insert an item/identity after
        // observation and have it overwritten or deleted by this pass.
        $envItems = $this->locked_environment_items((int) $menuTt, (string) $front['slug']);
        $environment = $this->index_environment_items($envItems, (string) $front['slug']);
        $envByUuid = $environment['by_uuid'];
        $ledgerItems = $this->locked_desired_ledger_items(
            (array) $front['items'],
            $envByUuid,
            (int) $menuTt,
            (string) $front['slug']
        );

        // Only after every existing/current-menu and ledger-resolved desired
        // physical row, identity sidecar, and relationship owner range is
        // locked may the first authored menu mutation occur.
        Db::update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $menuTermId], null, null, 'apply update menu term');
        CacheInvalidationTransaction::queue_term((int) $menuTermId, 'nav_menu', 'apply update menu term');

        // pass 1: ensure item rows
        $idByUuid = [];
        foreach ($front['items'] as $item) {
            $iu = $item['uuid'];
            $id = $envByUuid[$iu] ?? ($ledgerItems[$iu] ?? null);
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
                $identityRows = $this->fieldMaterializer->meta_owner_range_lock(
                    $wpdb->postmeta,
                    'post_id',
                    "menu {$front['slug']} inserted item identity readback"
                )->exact_key_rows($id, '_duo_uuid');
                if (count($identityRows) !== 1
                    || !is_string($identityRows[0]['meta_value'] ?? null)
                    || !hash_equals((string) $iu, $identityRows[0]['meta_value'])) {
                    throw new \RuntimeException(
                        "duo: menu {$front['slug']} inserted item $id did not persist one exact requested identity"
                    );
                }
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $id, 'term_taxonomy_id' => $menuTt, 'term_order' => 0,
                ], null, 'apply attach menu item');
                CacheInvalidationTransaction::queue_relationship($id, 'nav_menu', 'apply attach menu item');
            } elseif (!isset($envByUuid[$iu])) {
                // The preflight admits a ledger-resolved row only when it is
                // one unattached nav_menu_item with the exact requested UUID.
                // Attach that recoverable partial insert explicitly; silently
                // updating it would leave the desired menu membership absent.
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $id, 'term_taxonomy_id' => $menuTt, 'term_order' => 0,
                ], null, 'apply attach recovered menu item');
                CacheInvalidationTransaction::queue_relationship($id, 'nav_menu', 'apply attach recovered menu item');
            }
            $this->assert_locked_item_membership(
                (int) $id,
                (string) $iu,
                (int) $menuTt,
                (string) $front['slug']
            );
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
            CacheInvalidationTransaction::queue_post($id, 'nav_menu_item', 'apply update menu item');

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
            $lockedMetaRows = $this->fieldMaterializer->meta_owner_range_lock(
                $wpdb->postmeta,
                'post_id',
                "menu {$front['slug']} item managed-meta locking"
            )->read((int) $id);
            $exactMetaIds = [];
            foreach ($lockedMetaRows as $lockedMetaRow) {
                $slot = "k\0" . $lockedMetaRow['meta_key'];
                $exactMetaIds[$slot][] = MetaRows::positive_id($lockedMetaRow['meta_id']);
            }
            foreach ($metas as $k => $v) {
                $ids = array_values(array_filter(
                    $exactMetaIds["k\0" . $k] ?? [],
                    static fn(?int $metaId): bool => $metaId !== null
                ));
                foreach (array_slice($ids, 1) as $duplicateId) {
                    Db::delete(
                        $wpdb->postmeta,
                        ['meta_id' => $duplicateId],
                        null,
                        "apply delete duplicate menu-item managed meta '$k'"
                    );
                }
                $this->fieldMaterializer->upsert_locked_authored_meta(
                    $wpdb->postmeta,
                    'post_id',
                    (int) $id,
                    $k,
                    $v,
                    $ids[0] ?? null,
                    "apply reconcile menu-item managed meta '$k'"
                );
            }
            CacheInvalidationTransaction::queue(
                (int) $id,
                'post_meta',
                "menu {$front['slug']} managed-meta reconciliation"
            );

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
            $this->assert_locked_item_membership(
                (int) $id,
                (string) $item['uuid'],
                (int) $menuTt,
                (string) $front['slug']
            );
        }

        // The menu file owns the complete item set once the menu itself is
        // mapped or explicitly adopted. A target-created item has no
        // _duo_uuid by definition, so filtering the observation down to
        // uuid-bearing rows left exactly those hostile/default items behind
        // (exact core conformance, 9bd24d51: target custom item survived an
        // otherwise successful menu adoption and canonical recapture). Select
        // removals by physical id, while retaining the UUID only for ledger
        // cleanup. Refuse an item shared with another taxonomy instead of
        // deleting a post whose ownership extends outside this menu.
        foreach ($this->obsolete_environment_items($environment['by_id'], $idByUuid) as $id => $uuid) {
            $otherRelationships = $this->locked_other_relationships(
                (int) $id,
                (int) $menuTt,
                (string) $front['slug']
            );
            if ($otherRelationships !== []) {
                throw new \RuntimeException(
                    "duo: menu {$front['slug']}: target item $id has relationships outside this menu; refusing destructive reconciliation"
                );
            }
            Db::delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $menuTt], null, 'apply detach removed menu item');
            Db::delete($wpdb->postmeta, ['post_id' => $id], null, 'apply delete removed menu-item meta');
            Db::delete($wpdb->posts, ['ID' => $id], null, 'apply delete removed menu item');
            CacheInvalidationTransaction::queue_relationship($id, 'nav_menu', 'apply detach removed menu item');
            CacheInvalidationTransaction::queue_post($id, 'nav_menu_item', 'apply delete removed menu item');
            if ($uuid !== null) {
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
        $stylesheetRow = CacheInvalidationTransaction::lock_option_row(
            'stylesheet',
            'menu location active stylesheet locking'
        );
        $stylesheet = $stylesheetRow['option_value'] ?? null;
        if (!is_string($stylesheet)
            || $stylesheet === ''
            || strlen($stylesheet) > 764
            || preg_match('//u', $stylesheet) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $stylesheet) === 1) {
            throw new \RuntimeException('duo: menu location active stylesheet row is absent or malformed');
        }
        $name = 'theme_mods_' . $stylesheet;
        $locked = CacheInvalidationTransaction::lock_option_row($name, 'menu location theme-mod locking');
        $mods = $locked !== null ? PlainData::decode($locked['option_value'], "option '$name'") : [];
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

    private function assert_locked_menu_identity(int $termId, int $termTaxonomyId, string $menuSlug): void {
        global $wpdb;
        if ($termId <= 0 || $termTaxonomyId <= 0) {
            throw new \RuntimeException("duo: menu $menuSlug has no valid physical term/taxonomy identities");
        }
        $termIndex = $this->fieldMaterializer->proven_lock_index(
            $wpdb->terms,
            'term_id',
            "menu $menuSlug term identity locking",
            true
        );
        $wpdb->last_error = '';
        $termRows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} FORCE INDEX (`$termIndex`) "
            . 'WHERE term_id = %d ORDER BY term_id ASC LIMIT 2 FOR UPDATE',
            $termId
        ), ARRAY_A);
        if (!is_array($termRows)
            || !array_is_list($termRows)
            || count($termRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== ''
            || !is_array($termRows[0])
            || array_keys($termRows[0]) !== ['term_id']
            || MetaRows::positive_id($termRows[0]['term_id'] ?? null) !== $termId) {
            throw new \RuntimeException("duo: menu $menuSlug exact term identity lock/read failed");
        }

        $taxonomyIndex = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_taxonomy,
            'term_taxonomy_id',
            "menu $menuSlug taxonomy identity locking",
            true
        );
        $wpdb->last_error = '';
        $taxonomyRows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} "
            . "FORCE INDEX (`$taxonomyIndex`) WHERE term_taxonomy_id = %d "
            . 'ORDER BY term_taxonomy_id ASC LIMIT 2 FOR UPDATE',
            $termTaxonomyId
        ), ARRAY_A);
        if (!is_array($taxonomyRows)
            || !array_is_list($taxonomyRows)
            || count($taxonomyRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: menu $menuSlug exact taxonomy identity lock/read failed");
        }
        $taxonomyRow = $taxonomyRows[0];
        if (!is_array($taxonomyRow)
            || array_keys($taxonomyRow) !== ['term_taxonomy_id', 'term_id', 'taxonomy']
            || MetaRows::positive_id($taxonomyRow['term_taxonomy_id'] ?? null) !== $termTaxonomyId
            || MetaRows::positive_id($taxonomyRow['term_id'] ?? null) !== $termId
            || !is_string($taxonomyRow['taxonomy'] ?? null)
            || !hash_equals('nav_menu', $taxonomyRow['taxonomy'])) {
            throw new \RuntimeException("duo: menu $menuSlug taxonomy identity contradicts its term or native taxonomy");
        }
    }

    /** @return array<string,int> desired UUID => proven unattached local row */
    private function locked_desired_ledger_items(
        array $items,
        array $environmentByUuid,
        int $menuTermTaxonomyId,
        string $menuSlug
    ): array {
        $byId = [];
        foreach ($items as $item) {
            $uuid = is_array($item) ? ($item['uuid'] ?? null) : null;
            if (!is_string($uuid) || $uuid === '' || isset($environmentByUuid[$uuid])) {
                continue;
            }
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                continue;
            }
            if ($id <= 0 || (isset($byId[$id]) && !hash_equals($byId[$id], $uuid))) {
                throw new \RuntimeException("duo: menu $menuSlug desired ledger identities are malformed or collide");
            }
            $byId[$id] = $uuid;
        }
        ksort($byId, SORT_NUMERIC);
        $out = [];
        foreach ($byId as $id => $uuid) {
            $this->assert_locked_item_post((int) $id, $menuSlug);
            $this->assert_locked_item_identity((int) $id, $uuid, $menuSlug);
            $relationships = $this->locked_item_relationships((int) $id, $menuSlug);
            if ($relationships !== []) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug ledger-resolved desired item $id already belongs to a menu/taxonomy; refusing cross-menu takeover"
                );
            }
            $out[$uuid] = (int) $id;
        }
        return $out;
    }

    private function assert_locked_item_membership(
        int $itemId,
        string $uuid,
        int $menuTermTaxonomyId,
        string $menuSlug
    ): void {
        $this->assert_locked_item_post($itemId, $menuSlug);
        $this->assert_locked_item_identity($itemId, $uuid, $menuSlug);
        if ($this->locked_item_relationships($itemId, $menuSlug) !== [$menuTermTaxonomyId]) {
            throw new \RuntimeException(
                "duo: menu $menuSlug item $itemId exact locked relationship readback disagrees with desired membership"
            );
        }
    }

    private function assert_locked_item_post(int $itemId, string $menuSlug): void {
        global $wpdb;
        $postIndex = $this->fieldMaterializer->proven_lock_index(
            $wpdb->posts,
            'ID',
            "menu $menuSlug item post locking",
            true
        );
        $wpdb->last_error = '';
        $postRows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} FORCE INDEX (`$postIndex`) "
            . 'WHERE ID = %d ORDER BY ID ASC LIMIT 2 FOR UPDATE',
            $itemId
        ), ARRAY_A);
        if (!is_array($postRows)
            || !array_is_list($postRows)
            || count($postRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: menu $menuSlug item $itemId post lock/read failed");
        }
        $post = $postRows[0];
        if (!is_array($post)
            || array_keys($post) !== ['ID', 'post_type']
            || MetaRows::positive_id($post['ID'] ?? null) !== $itemId
            || !is_string($post['post_type'] ?? null)
            || !hash_equals('nav_menu_item', $post['post_type'])) {
            throw new \RuntimeException("duo: menu $menuSlug item $itemId is not one exact nav_menu_item row");
        }
    }

    private function assert_locked_item_identity(int $itemId, string $uuid, string $menuSlug): void {
        global $wpdb;
        $identity = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->postmeta,
            'post_id',
            "menu $menuSlug item identity locking"
        )->exact_key_rows($itemId, '_duo_uuid');
        if (count($identity) !== 1
            || !is_string($identity[0]['meta_value'] ?? null)
            || !hash_equals($uuid, $identity[0]['meta_value'])) {
            throw new \RuntimeException("duo: menu $menuSlug item $itemId has a missing, duplicate, or contradictory identity");
        }
    }

    /** @return list<array{ID:int,uuid:?string}> */
    private function locked_environment_items(int $menuTermTaxonomyId, string $menuSlug): array {
        global $wpdb;
        if ($menuTermTaxonomyId <= 0) {
            throw new \RuntimeException("duo: menu $menuSlug has no valid term-taxonomy identity");
        }
        $relationshipIndex = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_relationships,
            'term_taxonomy_id',
            "menu $menuSlug item relationship locking"
        );
        $wpdb->last_error = '';
        $relationshipRows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id FROM {$wpdb->term_relationships} FORCE INDEX (`$relationshipIndex`) "
            . 'WHERE term_taxonomy_id = %d ORDER BY object_id ASC LIMIT ' . (self::MAX_MENU_ITEMS + 1)
            . ' FOR UPDATE',
            $menuTermTaxonomyId
        ), ARRAY_A);
        if (!is_array($relationshipRows)
            || !array_is_list($relationshipRows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: menu $menuSlug item relationship lock read failed");
        }
        if (count($relationshipRows) > self::MAX_MENU_ITEMS) {
            throw new \RuntimeException("duo: menu $menuSlug exceeds the bounded item limit");
        }

        $metaLock = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->postmeta,
            'post_id',
            "menu $menuSlug item identity locking"
        );
        $out = [];
        $seen = [];
        foreach ($relationshipRows as $position => $relationshipRow) {
            $id = is_array($relationshipRow) && array_keys($relationshipRow) === ['object_id']
                ? MetaRows::positive_id($relationshipRow['object_id'])
                : null;
            if ($id === null || isset($seen[$id])) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug item relationship lock returned a malformed/duplicate row at position $position"
                );
            }
            $seen[$id] = true;
            $this->assert_locked_item_post($id, $menuSlug);
            $exactIdentity = array_column($metaLock->exact_key_rows($id, '_duo_uuid'), 'meta_value');
            if (count($exactIdentity) > 1) {
                throw new \RuntimeException("duo: menu $menuSlug item $id has duplicate exact identity rows");
            }
            $uuid = $exactIdentity[0] ?? null;
            if ($uuid !== null && !is_string($uuid)) {
                throw new \RuntimeException("duo: menu $menuSlug item $id has a malformed identity value");
            }
            if ($this->locked_item_relationships($id, $menuSlug) !== [$menuTermTaxonomyId]) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug item $id has relationship ownership outside the exact current menu"
                );
            }
            $out[] = ['ID' => $id, 'uuid' => $uuid];
        }
        return $out;
    }

    /** @return list<int> */
    private function locked_other_relationships(int $itemId, int $menuTt, string $menuSlug): array {
        return array_values(array_filter(
            $this->locked_item_relationships($itemId, $menuSlug),
            static fn(int $termTaxonomyId): bool => $termTaxonomyId !== $menuTt
        ));
    }

    /** @return list<int> */
    private function locked_item_relationships(int $itemId, string $menuSlug): array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_relationships,
            'object_id',
            "menu $menuSlug item relationship ownership locking"
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} FORCE INDEX (`$index`) "
            . 'WHERE object_id = %d ORDER BY term_taxonomy_id ASC LIMIT '
            . (self::MAX_ITEM_RELATIONSHIPS + 1) . ' FOR UPDATE',
            $itemId
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                "duo: menu $menuSlug: could not lock relationship ownership for target item $itemId"
            );
        }
        if (count($rows) > self::MAX_ITEM_RELATIONSHIPS) {
            throw new \RuntimeException("duo: menu $menuSlug item $itemId exceeds the relationship bound");
        }
        $relationships = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $tt = is_array($row) && array_keys($row) === ['term_taxonomy_id']
                ? MetaRows::positive_id($row['term_taxonomy_id'])
                : null;
            if ($tt === null || isset($seen[$tt])) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug item $itemId has a malformed/duplicate relationship at position $position"
                );
            }
            $seen[$tt] = true;
            $relationships[] = $tt;
        }
        return $relationships;
    }

    /**
     * Index every physical item attached to one menu, including rows without
     * an identity sidecar. Duplicate or contradictory sidecars are ambiguous
     * ownership, never a reason to pick the last database row.
     *
     * @return array{by_uuid:array<string,int>,by_id:array<int,?string>}
     */
    private function index_environment_items(array $rows, string $menuSlug): array {
        $byUuid = [];
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row['ID'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException("duo: menu $menuSlug: target item observation has no valid local id");
            }
            $uuid = trim((string) ($row['uuid'] ?? ''));
            $uuid = $uuid === '' ? null : $uuid;
            if (array_key_exists($id, $byId)
                && $uuid !== null
                && $byId[$id] !== null
                && $byId[$id] !== $uuid) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug: target item $id has contradictory identity sidecars"
                );
            }
            if ($uuid !== null && isset($byUuid[$uuid]) && $byUuid[$uuid] !== $id) {
                throw new \RuntimeException(
                    "duo: menu $menuSlug: identity $uuid belongs to multiple target items"
                );
            }
            $byId[$id] = $uuid ?? ($byId[$id] ?? null);
            if ($uuid !== null) {
                $byUuid[$uuid] = $id;
            }
        }
        ksort($byId, SORT_NUMERIC);
        ksort($byUuid, SORT_STRING);
        return ['by_uuid' => $byUuid, 'by_id' => $byId];
    }

    /** @return array<int,?string> local id => optional canonical UUID */
    private function obsolete_environment_items(array $byId, array $idByUuid): array {
        $keepIds = array_fill_keys(array_map('intval', array_values($idByUuid)), true);
        return array_filter(
            $byId,
            static fn(?string $uuid, int $id): bool => !isset($keepIds[$id]),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
