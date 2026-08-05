<?php
namespace Duo;

/**
 * Plan + apply: repo state tree -> environment DB.
 *
 * Plan is a three-way comparison per entity: file (target), duo_state ledger
 * hash (base = last sync), env snapshot (actual). Apply is two-phase — insert
 * rows with placeholder refs, then resolve refs through the ledger — inside a
 * transaction, with the side-effect canary armed; the rebuild pass (recounts,
 * attachment metadata, cache flush) runs after the canary disarms.
 */
final class Apply {
    private Policy $policy;
    private Tokens $tokens;
    private string $repo;
    /** @var string[] */
    private array $warnings = [];
    /** @var array<string,int> login -> user id */
    private array $userIds = [];
    private ?int $defaultAuthor = null;
    /** @var array{by_post_type: array<string,string[]>, term_object: string[]}|null
     *  memoized — see taxes_by_object_type() */
    private ?array $taxesByObjectType = null;

    private function __construct(string $repo) {
        $this->repo = rtrim($repo, '/');
        $this->policy = Policy::load($repo);
        $this->tokens = new Tokens();
    }

    // ------------------------------------------------------------------ plan

    public static function plan(string $repo, array $opts = []): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        $a = new self($repo);
        return $a->build_plan($opts);
    }

    private function build_plan(array $opts): array {
        $tree = $this->load_tree();
        $env = Capture::snapshot($this->repo);
        $base = Ledger::all_state();
        $adopt = array_fill_keys(array_filter(explode(',', $opts['adopt_by_slug'] ?? '')), true);

        $plan = [
            'create' => [], 'update' => [], 'unchanged' => [], 'drift' => [],
            'conflict' => [], 'adopt' => [], 'collision' => [], 'delete' => [],
        ];
        foreach ($tree as $uuid => $e) {
            $fileH = $e['hash'];
            $envE = $env[$uuid] ?? null;
            $baseH = $base[$uuid]['content_hash'] ?? null;
            $row = ['uuid' => $uuid, 'type' => $e['type'], 'path' => $e['path']];
            if ($envE !== null) {
                if ($fileH === $envE['hash']) {
                    $plan['unchanged'][] = $row;
                } elseif ($baseH === null || $envE['hash'] === $baseH) {
                    $plan['update'][] = $row + ['first_sync' => $baseH === null];
                } elseif ($fileH === $baseH) {
                    $plan['drift'][] = $row;
                } else {
                    $plan['conflict'][] = $row;
                }
                continue;
            }
            $coll = $this->find_collision($e);
            if ($coll !== null) {
                $kindOk = isset($adopt[$e['type'] === 'menu' ? 'menus' : $e['type'] . 's']);
                if ($kindOk) {
                    $plan['adopt'][] = $row + ['env_id' => $coll];
                } else {
                    $plan['collision'][] = $row + ['env_id' => $coll];
                }
                continue;
            }
            $plan['create'][] = $row;
        }
        global $wpdb;
        $guards = $this->policy->delete_guards();
        foreach ($base as $uuid => $b) {
            if (isset($tree[$uuid])) {
                continue;
            }
            $row = ['uuid' => $uuid, 'type' => $b['entity_type']];
            if ($b['entity_type'] === 'post' && $guards) {
                $localId = Ledger::id_for($uuid, Ledger::KIND_POST);
                if ($localId !== null) {
                    $ptype = (string) $wpdb->get_var($wpdb->prepare(
                        "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $localId
                    ));
                    $row['post_type'] = $ptype;
                    foreach ($guards["post:$ptype"] ?? [] as $g) {
                        $refs = $this->count_guard_refs($g, $localId);
                        if ($refs === null) {
                            $this->warnings[] = "delete guard table '{$g['table']}' not present; guard skipped for $uuid";
                            continue;
                        }
                        if ($refs > 0) {
                            $row['blocked'] = ($g['reason'] ?? "referenced by {$g['table']}.{$g['column']}") . " — $refs row(s)";
                            break;
                        }
                    }
                }
            }
            $plan['delete'][] = $row;
        }
        return $plan;
    }

    /** Phase-2 finalize order: 'early' post types first, stable otherwise. */
    private function phase2_rank(array $entity): int {
        if ($entity['type'] === 'post'
            && $this->policy->post_type_phase($entity['post_type'] ?? '') === 'early') {
            return 0;
        }
        return 1;
    }

    /** @return ?int row count, or null when the guard table doesn't exist */
    private function count_guard_refs(array $guard, int $localId): ?int {
        global $wpdb;
        $table = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $guard['table']);
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $guard['column']);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return null;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE `$column` = %d", $localId
        ));
    }

    /** Same-slug env entity: managed w/ different uuid (hard collision) or unmanaged (adoptable). */
    private function find_collision(array $e): ?int {
        global $wpdb;
        if ($e['type'] === 'post') {
            [$front] = Canon::parse_post_file($e['content']);
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_name = %s AND p.post_type = %s LIMIT 1",
                $front['slug'], $front['type']
            ));
            return $id ? (int) $id : null;
        }
        if ($e['type'] === 'term' || $e['type'] === 'menu') {
            $front = Canon::decode($e['content']);
            $tax = $e['type'] === 'menu' ? 'nav_menu' : $front['taxonomy'];
            $slug = $front['slug'];
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE t.slug = %s AND tt.taxonomy = %s LIMIT 1",
                $slug, $tax
            ));
            return $id ? (int) $id : null;
        }
        return null;
    }

    /** @return array<string, array{type:string, path:string, hash:string, content:string}> */
    private function load_tree(): array {
        $stateDir = $this->repo . '/state';
        if (!is_dir($stateDir)) {
            throw new \RuntimeException("duo: no state/ directory in {$this->repo}");
        }
        $out = [];
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $f) {
            $content = Canon::read_file($f);
            [$front] = Canon::parse_post_file($content);
            $out[$front['uuid']] = [
                'type' => 'post', 'post_type' => $front['type'],
                'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content), 'content' => $content,
            ];
        }
        foreach (glob($stateDir . '/terms/*/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $out[$front['uuid']] = [
                'type' => 'term', 'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content), 'content' => $content,
            ];
        }
        foreach (glob($stateDir . '/menus/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $out[$front['uuid']] = [
                'type' => 'menu', 'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content), 'content' => $content,
            ];
        }
        $optFile = $stateDir . '/options/core.json';
        if (is_file($optFile)) {
            $content = Canon::read_file($optFile);
            $out['options/core'] = [
                'type' => 'options', 'path' => 'options/core.json',
                'hash' => hash('sha256', $content), 'content' => $content,
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- apply

    public static function apply(string $repo, array $opts = []): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        $a = new self($repo);
        return $a->run($opts);
    }

    private function run(array $opts): array {
        global $wpdb;
        $plan = $this->build_plan($opts);
        $tree = $this->load_tree();

        if ($plan['collision']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['type']} {$r['path']} collides with env id {$r['env_id']} (same slug, different/no uuid)",
                $plan['collision']
            ));
            throw new \RuntimeException(
                "duo: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus adopts unmanaged rows):\n  - $list"
            );
        }
        if ($plan['conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_column($plan['conflict'], 'path'));
            throw new \RuntimeException(
                "duo: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - $list"
            );
        }

        if (!empty($opts['with_deletes'])) {
            $blocked = array_filter($plan['delete'], fn($r) => isset($r['blocked']));
            if ($blocked && empty($opts['force_delete_referenced'])) {
                $list = implode("\n  - ", array_map(
                    fn($r) => "{$r['type']} {$r['uuid']}: {$r['blocked']}",
                    $blocked
                ));
                throw new \RuntimeException(
                    "duo: deletes blocked by referential guards (this environment's runtime data references them; --force-delete-referenced to override):\n  - $list"
                );
            }
            foreach ($blocked as $r) {
                $this->warnings[] = "FORCED delete of guarded {$r['type']} {$r['uuid']} ({$r['blocked']})";
            }
        }

        $this->defaultAuthor = $this->resolve_login($opts['default_author'] ?? '') ?? null;
        $this->tokens->defaultUserId = $this->defaultAuthor;

        // Deterministic, declared ordering for BOTH phases: 'early' post types
        // (definition CPTs) lead — phase 1 row creation was glob-alphabetical
        // luck until the FSE frontier report flagged it (phase 2 was fixed in
        // task #10; usort is stable on PHP 8).
        $work = array_merge(
            $plan['create'],
            $plan['adopt'],
            $plan['update'],
            array_map(fn($r) => $r, $plan['conflict']) // only reachable with force_theirs
        );
        usort($work, fn($x, $y) =>
            $this->phase2_rank($tree[$x['uuid']]) <=> $this->phase2_rank($tree[$y['uuid']]));

        Canary::arm();
        $wpdb->query('START TRANSACTION');
        $newAttachmentIds = [];
        try {
            // ---- adopt: claim unmanaged env rows by writing identity ----
            foreach ($plan['adopt'] as $r) {
                $this->adopt($r, $tree[$r['uuid']]);
            }

            // ---- phase 1: rows exist with placeholder refs ----
            foreach ($work as $r) {
                $e = $tree[$r['uuid']];
                if ($e['type'] === 'term') {
                    $this->ensure_term_row(Canon::decode($e['content']), 'term');
                } elseif ($e['type'] === 'menu') {
                    $front = Canon::decode($e['content']);
                    $this->ensure_term_row([
                        'uuid' => $front['uuid'], 'taxonomy' => 'nav_menu',
                        'name' => $front['name'], 'slug' => $front['slug'],
                        'description' => '', 'parent' => null,
                    ], 'menu');
                } elseif ($e['type'] === 'post') {
                    [$front] = Canon::parse_post_file($e['content']);
                    $isNew = $this->ensure_post_row($front);
                    if ($isNew && $front['type'] === 'attachment') {
                        $newAttachmentIds[] = Ledger::id_for($front['uuid'], Ledger::KIND_POST);
                    }
                }
            }

            // ---- phase 2: resolve refs, full field/meta/relationship state ----
            // 'early' post types (manifest post_types {"phase": "early"}, e.g.
            // acf-field*) finalize first: interpreters read their finalized
            // content from the DB to type other entities' meta. Stable sort —
            // everything else keeps tree order.
            $phase2 = $work;
            usort($phase2, fn($x, $y) =>
                $this->phase2_rank($tree[$x['uuid']]) <=> $this->phase2_rank($tree[$y['uuid']]));
            foreach ($phase2 as $r) {
                $e = $tree[$r['uuid']];
                if ($e['type'] === 'term') {
                    $this->finalize_term(Canon::decode($e['content']));
                } elseif ($e['type'] === 'post') {
                    [$front, $body] = Canon::parse_post_file($e['content']);
                    $this->finalize_post($front, $body);
                } elseif ($e['type'] === 'menu') {
                    $this->finalize_menu(Canon::decode($e['content']));
                } elseif ($e['type'] === 'options') {
                    $this->apply_options(Canon::decode($e['content']));
                }
            }

            // ---- deletes (flag-gated; referential guards are post-v0) ----
            $deleted = [];
            if (!empty($opts['with_deletes'])) {
                foreach ($plan['delete'] as $r) {
                    $this->delete_entity($r['uuid'], $r['type']);
                    $deleted[] = $r['uuid'];
                }
            }

            $violations = Canary::violations();
            if ($violations) {
                $wpdb->query('ROLLBACK');
                Canary::disarm();
                throw new \RuntimeException(
                    "duo: side-effect canary tripped, transaction rolled back:\n  - " . implode("\n  - ", $violations)
                );
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $t) {
            $wpdb->query('ROLLBACK');
            Canary::disarm();
            throw $t;
        }
        Canary::disarm();

        // ---- ledger bookkeeping ----
        foreach (array_merge($plan['unchanged'], $work) as $r) {
            $e = $tree[$r['uuid']];
            Ledger::set_state_hash($r['uuid'], $e['type'], $e['hash']);
        }
        if (!empty($opts['with_deletes'])) {
            foreach ($plan['delete'] as $r) {
                Ledger::forget($r['uuid']);
            }
        }
        if (!empty($opts['revision'])) {
            Ledger::kv_set('applied_revision', (string) $opts['revision']);
        }

        // ---- rebuild pass (derived state; canary is off by design) ----
        $this->rebuild($newAttachmentIds, count($work) > 0);

        return [
            'plan' => array_map('count', $plan),
            'applied' => count($work),
            'drift' => array_column($plan['drift'], 'path'),
            'warnings' => array_merge($this->warnings, $this->tokens->warnings),
            'canary' => 'clean',
        ];
    }

    // ------------------------------------------------------- entity plumbing

    private function adopt(array $row, array $e): void {
        global $wpdb;
        $envId = (int) $row['env_id'];
        if ($e['type'] === 'post') {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1", $envId
            ));
            if (!$existing) {
                $wpdb->insert($wpdb->postmeta, ['post_id' => $envId, 'meta_key' => '_duo_uuid', 'meta_value' => $row['uuid']]);
            }
            Ledger::set($row['uuid'], 'post', Ledger::KIND_POST, $envId);
            $this->warnings[] = "adopted env post $envId as {$row['uuid']} ({$row['path']})";
        } else {
            $tt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = %d LIMIT 1", $envId
            ));
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1", $envId
            ));
            if (!$existing) {
                $wpdb->insert($wpdb->termmeta, ['term_id' => $envId, 'meta_key' => '_duo_uuid', 'meta_value' => $row['uuid']]);
            }
            Ledger::set($row['uuid'], $e['type'], Ledger::KIND_TERM, $envId);
            Ledger::set($row['uuid'], $e['type'], Ledger::KIND_TT, $tt);
            $this->warnings[] = "adopted env term $envId as {$row['uuid']} ({$row['path']})";
        }
    }

    private function ensure_term_row(array $front, string $entityType): void {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_TERM) !== null) {
            return;
        }
        $wpdb->insert($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug'], 'term_group' => 0]);
        $termId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->term_taxonomy, [
            'term_id' => $termId, 'taxonomy' => $front['taxonomy'],
            'description' => '', 'parent' => 0, 'count' => 0,
        ]);
        $tt = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => '_duo_uuid', 'meta_value' => $front['uuid']]);
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TERM, $termId);
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TT, $tt);
    }

    /** @return bool true when a new row was inserted */
    private function ensure_post_row(array $front): bool {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_POST) !== null) {
            return false;
        }
        $wpdb->insert($wpdb->posts, [
            'post_author' => 0,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => '',
            'post_title' => $front['title'],
            'post_excerpt' => '',
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_password' => '',
            'post_name' => $front['slug'],
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => $this->tokens->home() . '/?duo=' . $front['uuid'],
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_type' => $front['type'],
            'post_mime_type' => $front['mime'] ?? '',
            'comment_count' => 0,
        ]);
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $front['uuid']]);
        Ledger::set($front['uuid'], 'post', Ledger::KIND_POST, $id);
        return true;
    }

    private function finalize_term(array $front): void {
        global $wpdb;
        $termId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = Ledger::id_for($front['parent'], Ledger::KIND_TERM)
                ?? throw new \RuntimeException("duo: term {$front['slug']}: parent {$front['parent']} not resolvable");
        }
        $wpdb->update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $termId]);
        $wpdb->update($wpdb->term_taxonomy, [
            'description' => $this->encode_description($front['taxonomy'], $front['description']),
            'parent' => $parentId,
        ], ['term_id' => $termId, 'taxonomy' => $front['taxonomy']]);
        $this->reconcile_term_relationships($termId, $front['taxonomy'], (array) ($front['relationships'] ?? []));
    }

    /**
     * Mirror of Capture::term_description(): a taxonomy declaring
     * `taxonomies.<tax>.description_refs` gets its token-bearing map
     * resolved back through the ledger and re-serialized with PHP's OWN
     * serialize() — so int-typed ids come back as `i:N;`, matching
     * Polylang's own writes byte-for-byte in TYPE, not just in decoded
     * value (docs/frontier/polylang.md verified this column is genuinely
     * int-typed, not the digit-string convention ACF/Yoast use elsewhere).
     * Every other taxonomy keeps the plain detokenize_text() treatment.
     */
    private function encode_description(string $taxonomy, $description): string {
        $rule = $this->policy->description_refs_for_taxonomy($taxonomy);
        if ($rule === null) {
            return $this->tokens->detokenize_text((string) $description);
        }
        $decoded = $this->tokens->struct_apply((array) $description, [['path' => '$.*', 'kind' => $rule['kind']]], null);
        return serialize($decoded);
    }

    /**
     * Term-object symmetry of reconcile_relationships(): a term's own
     * membership in OTHER taxonomies as object_id (docs/frontier/
     * polylang.md's "term-object relationship capture/apply" — Polylang's
     * term_language/term_translations). Scoped to term_object_taxes() — the
     * same object_type collision guard reconcile_relationships() applies
     * for posts — so this never touches a colliding POST's own
     * relationship rows just because the numeric id matches. Two-phase-
     * safe for free: this only ever runs in phase 2 (finalize_term()),
     * after phase 1 has already inserted every term row (source AND
     * target) and its ledger entries for this whole apply run.
     */
    private function reconcile_term_relationships(int $termId, string $taxonomy, array $relField): void {
        global $wpdb;
        $taxes = $this->term_object_taxes();
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($relField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this environment currently owns as term-
                // object (stale file from before this capability existed,
                // or a hand edit) — never let it reach the ledger lookup /
                // INSERT below, mirroring reconcile_relationships()'s
                // identical guard on the post side.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("duo: term {$termId} ($taxonomy) references unresolvable term $u ($tax)");
                $desiredTt[$tt] = true;
            }
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        )) ?: [];
        foreach ($current as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                $wpdb->delete($wpdb->term_relationships, ['object_id' => $termId, 'term_taxonomy_id' => (int) $tt]);
            }
        }
        foreach (array_keys($desiredTt) as $tt) {
            if (!in_array((string) $tt, array_map('strval', $current), true)) {
                $wpdb->insert($wpdb->term_relationships, [
                    'object_id' => $termId, 'term_taxonomy_id' => $tt, 'term_order' => 0,
                ]);
            }
        }
    }

    private function finalize_post(array $front, string $body): void {
        global $wpdb;
        $id = Ledger::id_for($front['uuid'], Ledger::KIND_POST)
            ?? throw new \RuntimeException("duo: post {$front['uuid']} missing from ledger after phase 1");

        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = $this->tokens->token_to_id($front['parent']);
        }
        $authorId = 0;
        if (!empty($front['author'])) {
            $login = substr((string) $front['author'], 5); // strip "user:"
            $authorId = $this->resolve_login($login)
                ?? $this->defaultAuthor
                ?? 1;
            if ($this->resolve_login($login) === null) {
                $this->warnings[] = "post {$front['slug']}: author '$login' not in this environment; fell back to user #$authorId";
            }
        }

        $content = $this->policy->body_mode($front['type']) === 'verbatim'
            ? $body
            : Blocks::apply_rewrite($body, $this->policy, $this->tokens);
        $wpdb->update($wpdb->posts, [
            'post_author' => $authorId,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => $content,
            'post_title' => $front['title'],
            'post_excerpt' => $this->tokens->detokenize_text((string) $front['excerpt']),
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_name' => $front['slug'],
            'post_modified' => $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_parent' => $parentId,
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_mime_type' => $front['mime'] ?? '',
        ], ['ID' => $id]);

        // authored meta reconciliation: we own exactly the authored-classified keys
        $frontMeta = (array) ($front['meta'] ?? []);
        $desired = [];
        foreach ($frontMeta as $key => $v) {
            $rule = $this->policy->meta_rule_for_post($key, $frontMeta) ?? [];
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $v = $this->encode_structured($v, $rule);
            } elseif (!empty($rule['ref'])) {
                $v = $this->tokens->meta_tokens_to_value($v, $rule);
            } elseif (is_string($v)) {
                $v = $this->tokens->detokenize_text($v);
            }
            $desired[$key] = maybe_serialize($v);
        }
        $envMeta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $id
        ), ARRAY_A) ?: [];
        $envFlat = [];
        foreach ($envMeta as $m) {
            $envFlat[$m['meta_key']] ??= $m['meta_value'];
        }
        foreach ($envMeta as $m) {
            $rule = $this->policy->meta_rule_for_post($m['meta_key'], $envFlat);
            if (($rule['class'] ?? '') === 'authored' && !array_key_exists($m['meta_key'], $desired)) {
                $wpdb->delete($wpdb->postmeta, ['meta_id' => $m['meta_id']]);
            }
        }
        foreach ($desired as $key => $val) {
            $this->upsert_meta($wpdb->postmeta, 'post_id', $id, $key, $val);
        }

        // term relationships for owned taxonomies
        if ($front['type'] !== 'attachment') {
            $this->reconcile_relationships($id, $front['type'], (array) ($front['terms'] ?? []));
        }

        // attachment binary + managed meta
        if ($front['type'] === 'attachment') {
            $this->place_attachment($id, $front);
        }
    }

    private function reconcile_relationships(int $postId, string $postType, array $termsField): void {
        global $wpdb;
        $taxes = $this->taxes_for_post_type($postType);
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($termsField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this post type actually owns (stale file from
                // before the object-type filter existed, or a hand edit) —
                // never let it reach the ledger lookup / INSERT below.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("duo: post $postId references unresolvable term $u ($tax)");
                $desiredTt[$tt] = true;
            }
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $postId
        )) ?: [];
        foreach ($current as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                $wpdb->delete($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => (int) $tt]);
            }
        }
        foreach (array_keys($desiredTt) as $tt) {
            if (!in_array((string) $tt, array_map('strval', $current), true)) {
                $wpdb->insert($wpdb->term_relationships, [
                    'object_id' => $postId, 'term_taxonomy_id' => $tt, 'term_order' => 0,
                ]);
            }
        }
    }

    /**
     * Same collision guard as Capture::taxes_by_object_type(): only
     * taxonomies whose registered object_type actually includes this post
     * type may own this post's relationship rows. Without it, the "current
     * relationships" SELECT above can pick up a colliding term's own
     * term-to-term rows (object_id happens to equal this post's id) and,
     * since they're never in $desiredTt, DELETE them — destroying a
     * different object's genuine data because of a numeric coincidence.
     *
     * Computed together with term_object_taxes() below (one get_taxonomy()
     * walk, one warning per unregistered taxonomy instead of two) and
     * memoized per apply run; the taxonomy roster doesn't change mid-run.
     *
     * @return array{by_post_type: array<string,string[]>, term_object: string[]}
     */
    private function taxes_by_object_type(): array {
        if ($this->taxesByObjectType === null) {
            $byPostType = [];
            $termObject = [];
            foreach ($this->policy->taxonomies() as $tax) {
                $taxObj = get_taxonomy($tax);
                if ($taxObj === false) {
                    $this->warnings[] =
                        "taxonomy '$tax' is in policy scope but not registered on this environment"
                        . " (plugin inactive?) — cannot determine which object type its relationships"
                        . " belong to, so its relationships are skipped for every post and term on apply";
                    continue;
                }
                foreach ((array) $taxObj->object_type as $objectType) {
                    if ($objectType === 'term') {
                        $termObject[] = $tax;
                    } else {
                        $byPostType[$objectType][] = $tax;
                    }
                }
            }
            $this->taxesByObjectType = ['by_post_type' => $byPostType, 'term_object' => $termObject];
        }
        return $this->taxesByObjectType;
    }

    private function taxes_for_post_type(string $postType): array {
        return $this->taxes_by_object_type()['by_post_type'][$postType] ?? [];
    }

    /** @return string[] policy-scoped taxonomies whose registered object_type includes 'term'. */
    private function term_object_taxes(): array {
        return $this->taxes_by_object_type()['term_object'];
    }

    private function place_attachment(int $id, array $front): void {
        global $wpdb;
        $src = $this->repo . '/media/' . $front['media'];
        if (!is_file($src)) {
            throw new \RuntimeException("duo: media blob {$front['media']} missing from repo");
        }
        $up = wp_upload_dir(null, false);
        $dst = trailingslashit($up['basedir']) . $front['file'];
        if (!is_file($dst) || hash_file('sha256', $dst) !== hash_file('sha256', $src)) {
            Canon::write_file($dst, Canon::read_file($src));
        }
        $this->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attached_file', $front['file']);
        $this->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attachment_image_alt', (string) ($front['alt'] ?? ''));
    }

    private function finalize_menu(array $front): void {
        global $wpdb;
        $menuTermId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $menuTt = Ledger::id_for($front['uuid'], Ledger::KIND_TT);
        $wpdb->update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $menuTermId]);

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
                $wpdb->insert($wpdb->posts, [
                    'post_author' => 0, 'post_date' => '1970-01-01 00:00:00', 'post_date_gmt' => '1970-01-01 00:00:00',
                    'post_content' => '', 'post_title' => $item['title'], 'post_excerpt' => $item['attr_title'] ?? '',
                    'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
                    'post_password' => '', 'post_name' => $iu, 'to_ping' => '', 'pinged' => '',
                    'post_modified' => '1970-01-01 00:00:00', 'post_modified_gmt' => '1970-01-01 00:00:00',
                    'post_content_filtered' => '', 'post_parent' => 0,
                    'guid' => $this->tokens->home() . '/?duo=' . $iu,
                    'menu_order' => (int) $item['position'], 'post_type' => 'nav_menu_item',
                    'post_mime_type' => '', 'comment_count' => 0,
                ]);
                $id = (int) $wpdb->insert_id;
                $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $iu]);
                $wpdb->insert($wpdb->term_relationships, [
                    'object_id' => $id, 'term_taxonomy_id' => $menuTt, 'term_order' => 0,
                ]);
            }
            Ledger::set($iu, 'menu_item', Ledger::KIND_POST, $id);
            $idByUuid[$iu] = $id;
        }

        // pass 2: fields + metas (parents resolvable now)
        foreach ($front['items'] as $item) {
            $id = $idByUuid[$item['uuid']];
            $wpdb->update($wpdb->posts, [
                'post_title' => $item['title'],
                'post_excerpt' => (string) ($item['attr_title'] ?? ''),
                'menu_order' => (int) $item['position'],
                'post_status' => 'publish',
            ], ['ID' => $id]);

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
                $this->upsert_meta($wpdb->postmeta, 'post_id', $id, $k, $v);
            }
        }

        // remove env items no longer in the file (menu-scoped ownership)
        $keep = array_fill_keys(array_keys($idByUuid), true);
        foreach ($envByUuid as $uuid => $id) {
            if (!isset($keep[$uuid])) {
                $wpdb->delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $menuTt]);
                $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
                $wpdb->delete($wpdb->posts, ['ID' => $id]);
                Ledger::forget($uuid);
            }
        }

        // locations in the active theme's mods
        $this->assign_locations((int) $menuTermId, (array) ($front['locations'] ?? []));
    }

    private function assign_locations(int $menuTermId, array $locations): void {
        global $wpdb;
        $name = 'theme_mods_' . (string) get_option('stylesheet');
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        $mods = $raw !== null ? maybe_unserialize($raw) : [];
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
        $this->upsert_option($name, serialize($mods));
    }

    private function apply_options(array $options): void {
        foreach ($options as $name => $v) {
            $rule = $this->policy->option_rule($name) ?? [];
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $v = $this->encode_structured($v, $rule);
            } elseif (!empty($rule['ref'])) {
                $v = $this->tokens->tokens_to_value($v, $rule['ref']);
            } elseif (is_string($v)) {
                $v = $this->tokens->detokenize_text($v);
            }
            $this->upsert_option($name, maybe_serialize($v));
        }
    }

    /**
     * Mirror of Capture::decode_structured(): re-encode a json_refs/
     * key_refs-rewritten native structure back to the shape the RAW
     * meta/option value actually stores on the wire. `"json_encoded":
     * true` (Elementor's _elementor_data — the plugin manually
     * wp_json_encode()s before WordPress's own maybe_serialize()/
     * maybe_unserialize() layer, a no-op passthrough on an already-string
     * value, ever sees it) re-encodes to a compact JSON TEXT string —
     * deliberately plain `json_encode($v)` with NO flags, matching
     * Elementor's own convention byte-for-byte (escaped slashes, escaped
     * unicode — confirmed via docs/frontier/elementor.md's xxd check),
     * NOT Canon::encode() (which sorts keys / pretty-prints / unescapes —
     * exactly right for the state/ tree's human-readable copy, exactly
     * wrong for reconstructing what a plugin's own code expects to read
     * back from postmeta). Absent the flag, the native array is returned
     * as-is and the ordinary maybe_serialize() call at each call site
     * PHP-serializes it — the ordinary WP option/meta convention (Yoast's
     * wpseo_taxonomy_meta).
     */
    private function encode_structured($v, array $rule) {
        if (empty($rule['json_encoded'])) {
            return $v;
        }
        $encoded = json_encode($v);
        if ($encoded === false) {
            throw new \RuntimeException('duo: could not re-encode json_refs/key_refs structured value: ' . json_last_error_msg());
        }
        return $encoded;
    }

    private function upsert_option(string $name, string $value): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($exists) {
            $wpdb->update($wpdb->options, ['option_value' => $value], ['option_name' => $name]);
        } else {
            $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $value, 'autoload' => 'yes']);
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /** $value null writes a real SQL NULL — byte-faithful to plugins that store
     *  NULL meta_value themselves (WooCommerce's date_expires on non-expiring
     *  coupons); never a "delete the row" semantic. */
    private function upsert_meta(string $table, string $fkCol, int $objectId, string $key, ?string $value): void {
        global $wpdb;
        $metaId = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $table WHERE $fkCol = %d AND meta_key = %s LIMIT 1", $objectId, $key
        ));
        if ($metaId) {
            $wpdb->update($table, ['meta_value' => $value], ['meta_id' => $metaId]);
        } else {
            $wpdb->insert($table, [$fkCol => $objectId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }

    private function delete_entity(string $uuid, string $type): void {
        global $wpdb;
        if ($type === 'post') {
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id !== null) {
                $postType = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $id
                ));
                $this->delete_post_relationships($id, $postType);
                $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
                $wpdb->delete($wpdb->posts, ['ID' => $id]);
            }
        } elseif ($type === 'term' || $type === 'menu') {
            $termId = Ledger::id_for($uuid, Ledger::KIND_TERM);
            $tt = Ledger::id_for($uuid, Ledger::KIND_TT);
            if ($termId !== null) {
                // This term's OWN outbound relationships (term-object taxonomies
                // where THIS term is object_id — capability symmetric to
                // delete_post_relationships() below) must go BEFORE the target-
                // side cleanup and the row deletes, for the same reason: an
                // unfiltered delete keyed on the bare id risks nothing here
                // (term_relationships has no other FK into wp_terms), but
                // leaving these rows would orphan-reference a term_id that's
                // about to stop existing.
                $this->delete_term_relationships($termId);
            }
            if ($tt !== null) {
                $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $tt]);
                $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $tt]);
            }
            if ($termId !== null) {
                $wpdb->delete($wpdb->termmeta, ['term_id' => $termId]);
                $wpdb->delete($wpdb->terms, ['term_id' => $termId]);
            }
        }
        $this->warnings[] = "deleted $type $uuid";
    }

    /**
     * Delete a post's own term_relationships rows only — scoped to every
     * taxonomy REGISTERED on this runtime whose object_type includes this
     * post's type (deliberately not policy-scoped: a full post delete must
     * clean up every taxonomy that legitimately relates to it, same as
     * wp_delete_post(), not just the ones Duo happens to manage).
     *
     * An unfiltered `DELETE ... WHERE object_id = $id` (the previous code)
     * hits every term_relationships row with that raw id regardless of
     * taxonomy — including a term-object taxonomy's rows for a completely
     * different TERM that happens to have the same id, since posts and
     * terms are minted from independent auto-increment counters sharing
     * one numeric space. That would silently destroy the colliding term's
     * genuine data as a side effect of deleting an unrelated post.
     */
    private function delete_post_relationships(int $id, string $postType): void {
        global $wpdb;
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) use ($postType) {
            $taxObj = get_taxonomy($tax);
            return $taxObj !== false && in_array($postType, (array) $taxObj->object_type, true);
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $wpdb->query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $id
        ));
    }

    /**
     * Term-object symmetry of delete_post_relationships() immediately
     * above: a deleted term's OWN relationship rows as object_id (term-
     * object taxonomies, e.g. Polylang's term_language/term_translations),
     * scoped to every taxonomy REGISTERED on this runtime whose object_type
     * includes 'term' — deliberately not policy-scoped, same rationale as
     * the post-side twin: a full term delete must clean up every taxonomy
     * that legitimately relates to it as object_id, not just the ones Duo
     * happens to manage. An unfiltered `DELETE ... WHERE object_id = $id`
     * would hit every term_relationships row with that raw id regardless of
     * taxonomy — including a POST-object taxonomy's row for a completely
     * different POST that happens to share this term's id.
     */
    private function delete_term_relationships(int $termId): void {
        global $wpdb;
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) {
            $taxObj = get_taxonomy($tax);
            return $taxObj !== false && in_array('term', (array) $taxObj->object_type, true);
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $wpdb->query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        ));
    }

    private function resolve_login(string $login): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        if (!isset($this->userIds[$login])) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login
            ));
            $this->userIds[$login] = $id ? (int) $id : 0;
        }
        return $this->userIds[$login] ?: null;
    }

    // --------------------------------------------------------------- rebuild

    private function rebuild(array $newAttachmentIds, bool $didWork = true): void {
        global $wpdb;

        // term recounts (published posts), incl. nav_menu
        $taxes = array_merge($this->policy->taxonomies(), ['nav_menu']);
        $in = "'" . implode("','", array_map('esc_sql', array_unique($taxes))) . "'";
        $wpdb->query(
            "UPDATE {$wpdb->term_taxonomy} tt SET count = (
                SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.post_status = 'publish'
             ) WHERE tt.taxonomy IN ($in)"
        );

        // attachment metadata (thumbnails etc.) — derived, regenerated
        foreach (array_filter($newAttachmentIds) as $id) {
            try {
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                }
                $file = get_attached_file($id);
                if ($file && is_file($file)) {
                    $meta = wp_generate_attachment_metadata($id, $file);
                    if ($meta) {
                        $this->upsert_meta($wpdb->postmeta, 'post_id', (int) $id, '_wp_attachment_metadata', maybe_serialize($meta));
                    }
                }
            } catch (\Throwable $t) {
                $this->warnings[] = "attachment $id metadata regen failed: " . $t->getMessage();
            }
        }

        // manifest-declared rebuilders: the hooks we deliberately skip are also
        // what maintain plugin derived state (indexables, lookup tables) —
        // manifests declare the regeneration command instead.
        if ($didWork) {
            foreach ($this->policy->rebuilders() as $r) {
                $cmd = (string) ($r['command'] ?? '');
                if ($cmd === '') {
                    continue;
                }
                if (!class_exists('\WP_CLI')) {
                    $this->warnings[] = "rebuilder '$cmd' skipped (not a wp-cli context)";
                    continue;
                }
                try {
                    $res = \WP_CLI::runcommand($cmd, ['launch' => true, 'return' => 'all', 'exit_error' => false]);
                    if ((int) $res->return_code !== 0) {
                        $this->warnings[] = "rebuilder '$cmd' exited {$res->return_code}: " . trim((string) $res->stderr);
                    }
                } catch (\Throwable $t) {
                    $this->warnings[] = "rebuilder '$cmd' failed: " . $t->getMessage();
                }
            }
        }

        wp_cache_flush();
    }
}
