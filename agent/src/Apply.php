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
    private CompiledRepository $compiled;
    private string $repo;
    /** @var string[] */
    private array $warnings = [];
    /** @var array<string,int> login -> user id */
    private array $userIds = [];
    private ?int $defaultAuthor = null;
    /** @var array{by_post_type: array<string,string[]>, term_object: string[]}|null
     *  memoized — see taxes_by_object_type() */
    private ?array $taxesByObjectType = null;
    /** @var array|null memoized Snapshot::row_tables() — a pure-PHP manifest
     *  merge (no DB queries of its own), but every dispatch site below
     *  needs "is this entity type a declared table" cheaply and repeatedly,
     *  same rationale as taxesByObjectType's own memoization. */
    private ?array $snapshotRowTablesCache = null;

    private function __construct(string $repo, Policy $policy, CompiledRepository $compiled) {
        $this->repo = rtrim($repo, '/');
        $this->policy = $policy;
        $this->compiled = $compiled;
        $this->tokens = new Tokens();
    }

    private static function compiled(string $repo, Policy $policy, array $opts): CompiledRepository {
        $path = (string) ($opts['compiled'] ?? '');
        return $path !== ''
            ? RepositoryCompiler::read_artifact($path, $policy)
            : RepositoryCompiler::compile($repo, $policy);
    }

    /** @return array<string, array> declared authored_snapshot tables, keyed by table name (== entity type). */
    private function snapshotRowTables(): array {
        if ($this->snapshotRowTablesCache === null) {
            $this->snapshotRowTablesCache = Snapshot::row_tables($this->policy);
        }
        return $this->snapshotRowTablesCache;
    }

    // ------------------------------------------------------------------ plan

    public static function plan(string $repo, array $opts = []): array {
        $policy = Policy::load($repo);
        // Compile before constructing Tokens (which reads target options),
        // suppressing cron, or ensuring a ledger. A bad revision is a pure
        // offline result and is identical for fresh and mapped targets.
        $compiled = self::compiled($repo, $policy, $opts);
        Canary::suppress_cron_spawn();
        $a = new self($repo, $policy, $compiled);
        Ledger::ensure();
        $plan = $a->build_plan($opts, $compiled);
        // Only the plan-only entry point attaches warnings to the returned
        // array itself — run() below calls build_plan() too, but folds
        // $this->warnings into ITS OWN summary separately (see run()'s
        // return), so this must not become a build_plan() return-shape
        // change or apply's 'plan' => array_map('count', $plan) count block
        // would grow a spurious 'warnings' => N entry.
        $plan['warnings'] = $a->warnings;
        return $plan;
    }

    private function build_plan(array $opts, CompiledRepository $compiled): array {
        $tree = $compiled->tree();
        $this->check_theme_mismatch($tree);
        // Capture::snapshot() runs the SAME build() capture.php's own `duo
        // capture` does (drift detection needs the live environment's
        // current canonical view) — so it hits the identical task #73
        // loud-and-blocking gate on an unscoped ref-typed option. Threaded
        // through so plan/apply have the same escape hatch `duo capture`
        // does, matching this file's existing --force-* precedents.
        $env = Capture::snapshot($this->repo, !empty($opts['force_unresolved_refs']));
        $base = Ledger::all_state();
        $adopt = array_fill_keys(array_filter(explode(',', $opts['adopt_by_slug'] ?? '')), true);

        $plan = [
            'create' => [], 'update' => [], 'unchanged' => [], 'drift' => [],
            'conflict' => [], 'adopt' => [], 'collision' => [], 'delete' => [],
            'code_mismatch' => [], 'code_drift' => [],
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
                // Table entities' own 'type' IS the specific table name (see
                // Snapshot.php's row_tables() docblock) — pluralizing it
                // ("woocommerce_attribute_taxonomiess") the way posts/terms/
                // menus already do would be unusable, so every declared
                // table shares ONE adopt-by-slug key, "tables", regardless
                // of which specific table a natural_key collision belongs to.
                $kind = isset($this->snapshotRowTables()[$e['type']])
                    ? 'tables'
                    : ($e['type'] === 'menu' ? 'menus' : $e['type'] . 's');
                $kindOk = isset($adopt[$kind]);
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

        // docs/proposals/code-half.md §3.2: the cross-partition invariant's
        // plan-time checks — missing_in_code / outside_version_range — read
        // the TARGET state's active_plugins/template/stylesheet (this
        // tree's own options/core entity, when present) through the exact
        // same detector `wp duo deploy` itself refuses on (Deploy.php), so
        // ordinary `duo plan`/`duo status` and `duo deploy`/`duo apply` can
        // never disagree about what "in code" means. Surfaced here (not
        // only at deploy time) per §3.2: "Both checks run inside the
        // existing Apply::build_plan() ... so they show up in ordinary
        // 'duo plan'/'duo status', not just at deploy time." code_revision_
        // stale (§3.2 point 3) is deliberately not implemented — phase 1
        // has no code/ materialization step to populate either side of
        // that comparison; see Deploy::code_mismatch()'s docblock.
        $desired = isset($tree['options/core'])
            ? Deploy::extract_desired($tree['options/core']['data'])
            : [];
        $plan['code_mismatch'] = Deploy::code_mismatch($this->policy, $desired);
        // DUO-3231: same $desired, same call shape as code_mismatch above —
        // see Deploy::code_drift()'s own docblock for why it's a distinct
        // question (out-of-band version change vs. compatibility range).
        $plan['code_drift'] = Deploy::code_drift($this->policy, $desired);
        return $plan;
    }

    /**
     * Phase-2 finalize order: 'early' post types first, then ordinary
     * posts/terms/menus/options, then declared table rows in their own
     * topo order (parents before children — nf3_forms before nf3_fields/
     * nf3_actions) — offset past every other rank since nothing in this
     * round's scope cross-references between tables and posts/terms in
     * either direction, so their RELATIVE order to each other never
     * matters, only their INTERNAL order does.
     */
    private function phase2_rank(array $entity): int {
        if (isset($this->snapshotRowTables()[$entity['type']])) {
            return 2 + Snapshot::phase2_rank($this->policy, $entity['type']);
        }
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
        if (isset($this->snapshotRowTables()[$e['type']])) {
            // natural_key-identity tables only (e.g. woocommerce_attribute_
            // taxonomies pre-provisioned by hand on the target) — see
            // Snapshot::find_collision()'s own docblock; mapped-identity
            // tables have no collision concept and return null here.
            return Snapshot::find_collision($this->policy, $e);
        }
        if ($e['type'] === 'post') {
            $front = $e['data'];
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_name = %s AND p.post_type = %s LIMIT 1",
                $front['slug'], $front['type']
            ));
            return $id ? (int) $id : null;
        }
        if ($e['type'] === 'term' || $e['type'] === 'menu') {
            $front = $e['data'];
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

    /**
     * Active-theme-mismatch guard (docs/frontier/fse.md: "there is no
     * active-theme-mismatch guard — if the target environment's active
     * theme differs from the captured wp_theme term's slug, the applied
     * template silently becomes inert ... with zero warning anywhere in
     * the plan or apply output"). Verified empirically on the fse
     * conformance fixture: wp_theme is an ordinary POST-object taxonomy
     * (registered object_type wp_template/wp_template_part/
     * wp_global_styles) whose term identity IS the theme's own stylesheet
     * slug, so a captured wp_template/wp_template_part carries it in the
     * ordinary `terms.wp_theme` field — wp_navigation/wp_block carry no
     * wp_theme term at all (confirmed: their captured `terms` is always
     * `{}`), so this needs no post-type allowlist; it falls out for free
     * from whichever entities actually have a wp_theme relationship.
     * WordPress's template resolver only ever matches a row tagged for
     * get_option('stylesheet') — a row tagged for any OTHER theme applies
     * (the row lands, byte-identical) but never renders. Warning, never a
     * block: the data is correct: rendering is the only casualty.
     */
    private function check_theme_mismatch(array $tree): void {
        $themeSlugByUuid = [];
        foreach ($tree as $uuid => $e) {
            if ($e['type'] === 'term') {
                $front = $e['data'];
                if (($front['taxonomy'] ?? '') === 'wp_theme') {
                    $themeSlugByUuid[$uuid] = $front['slug'];
                }
            }
        }
        if (!$themeSlugByUuid) {
            return; // no wp_theme terms anywhere in this tree — not an FSE site, nothing to check
        }
        $active = (string) get_option('stylesheet');
        $affected = []; // captured theme slug => affected entity path list
        foreach ($tree as $e) {
            if ($e['type'] !== 'post') {
                continue;
            }
            $front = $e['data'];
            foreach ((array) ($front['terms']['wp_theme'] ?? []) as $themeUuid) {
                $slug = $themeSlugByUuid[$themeUuid] ?? null;
                if ($slug !== null && $slug !== $active) {
                    $affected[$slug][] = $e['path'];
                }
            }
        }
        foreach ($affected as $capturedTheme => $paths) {
            $verb = count($paths) === 1 ? 'is tagged for' : 'are tagged for';
            $this->warnings[] = "active-theme mismatch: this environment's active theme is '$active' but "
                . implode(', ', $paths) . " $verb theme '$capturedTheme'"
                . " — will apply but will NOT render until '$capturedTheme' is active here";
        }
    }

    // ----------------------------------------------------------------- apply

    public static function apply(string $repo, array $opts = []): array {
        $policy = Policy::load($repo);
        $compiled = self::compiled($repo, $policy, $opts);
        Canary::suppress_cron_spawn();
        $a = new self($repo, $policy, $compiled);
        Ledger::ensure();
        return $a->run($opts, $compiled);
    }

    private function run(array $opts, CompiledRepository $compiled): array {
        global $wpdb;
        $tree = $compiled->tree();
        $plan = $this->build_plan($opts, $compiled);

        if ($plan['collision']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['type']} {$r['path']} collides with env id {$r['env_id']} (same slug, different/no uuid)",
                $plan['collision']
            ));
            throw new \RuntimeException(
                "duo: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus,tables adopts unmanaged rows):\n  - $list"
            );
        }
        if ($plan['conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_column($plan['conflict'], 'path'));
            throw new \RuntimeException(
                "duo: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - $list"
            );
        }

        // docs/proposals/code-half.md §3.3's blocking posture: a code_mismatch
        // row only ever exists for an entry the TARGET state declares active
        // (Deploy::code_mismatch() is scoped that way by construction), so
        // every row here already qualifies — matching the two existing
        // --force-* precedents immediately around this one.
        if ($plan['code_mismatch'] && empty($opts['force_code_mismatch'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $plan['code_mismatch']));
            throw new \RuntimeException(
                "duo: apply refused — code_mismatch:\n\n$list\n\n"
                . "Run 'duo deploy <env>' first if this environment simply hasn't been deployed/reconciled yet, "
                . 'or pass --force-code-mismatch to proceed anyway.'
            );
        }

        // DUO-3231: same blocking posture and escape-hatch convention as
        // code_mismatch immediately above — see Deploy::code_drift()'s
        // docblock for what distinguishes the two questions.
        if ($plan['code_drift'] && empty($opts['force_code_drift'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $plan['code_drift']));
            throw new \RuntimeException(
                "duo: apply refused — code_drift:\n\n$list\n\n"
                . "Run 'duo deploy <env>' to reconcile and re-baseline, or pass --force-code-drift to proceed anyway."
            );
        }
        // Architecture Rulings §1 (report-not-hide): reaching this line with
        // findings present is only possible via --force-code-drift — surface
        // what was overridden, same convention as "FORCED delete of guarded
        // ..." below, so `wp duo apply`'s own (non --format=json) output
        // doesn't silently swallow it.
        foreach ($plan['code_drift'] as $r) {
            $this->warnings[] = 'FORCED past code_drift: ' . $r['message'];
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
                if (isset($this->snapshotRowTables()[$e['type']])) {
                    Snapshot::ensure_row($this->policy, $e);
                } elseif ($e['type'] === 'term') {
                    $this->ensure_term_row($e['data'], 'term');
                } elseif ($e['type'] === 'menu') {
                    $front = $e['data'];
                    $this->ensure_term_row([
                        'uuid' => $front['uuid'], 'taxonomy' => 'nav_menu',
                        'name' => $front['name'], 'slug' => $front['slug'],
                        'description' => '', 'parent' => null,
                    ], 'menu');
                } elseif ($e['type'] === 'post') {
                    $front = $e['data'];
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
                if (isset($this->snapshotRowTables()[$e['type']])) {
                    Snapshot::finalize_row($this->policy, $this->tokens, $e);
                } elseif ($e['type'] === 'term') {
                    $this->finalize_term($e['data']);
                } elseif ($e['type'] === 'post') {
                    $this->finalize_post($e['data'], $e['body']);
                } elseif ($e['type'] === 'menu') {
                    $this->finalize_menu($e['data']);
                } elseif ($e['type'] === 'options') {
                    $this->apply_options($e['data']);
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
        // The compiler revision is the truthful default receipt. An
        // orchestrator may still supply a git commit/ref for operator-facing
        // provenance, but a direct CLI apply no longer advances state with
        // an empty/ambiguous revision marker.
        Ledger::kv_set(
            'applied_revision',
            !empty($opts['revision']) ? (string) $opts['revision'] : $compiled->revision_hash()
        );

        // ---- rebuild pass (derived state; canary is off by design) ----
        $this->rebuild($newAttachmentIds, count($work) > 0);

        return [
            'artifact' => [
                'hash' => $compiled->artifact_hash(),
                'revision' => $compiled->revision_hash(),
                'manifests' => $compiled->manifest_hash(),
            ],
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
        if (isset($this->snapshotRowTables()[$e['type']])) {
            Snapshot::adopt($this->policy, $row['uuid'], $e['type'], $envId);
            $this->warnings[] = "adopted env table row {$e['type']}:$envId as {$row['uuid']} ({$row['path']})";
            return;
        }
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
            // task #88: written unconditionally even for a 'derived'-
            // classified field (e.g. product_variation's title) — a new
            // row needs SOME starting value and there's no rebuilder to
            // conjure one; finalize_post() below is where derived fields
            // stop being overwritten, once the row actually exists.
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
        $fields = [
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
        ];
        // Post-FIELD classification (task #88): a field this post_type
        // classifies 'derived' (v1 scope: product_variation's title —
        // WooCommerce's own hook-free self-heal, #72's root cause) is
        // dropped from this UPDATE entirely rather than overwritten with
        // the captured byte string, once the row already exists.
        // ensure_post_row() (phase 1, moments ago in this same apply for a
        // brand-new row) already wrote the captured value as a real
        // starting title — there's no rebuilder to conjure one the way
        // _wp_attachment_metadata gets one on create, and WordPress
        // requires SOME value on insert — so this only ever skips touching
        // an ALREADY-populated column, never leaves one null.
        //
        // Argued explicitly (task #88's report): the alternative —
        // overwrite it on every apply, same as any authored field — would
        // make a target environment's own, more-progressed self-heal
        // regress to a stale source snapshot on every single apply cycle,
        // only to re-heal itself on the very next ordinary WooCommerce read
        // (an admin view, a Store API request) — a pointless oscillation
        // for a value nothing authored actually controls. Letting the
        // plugin's own derivation stand once the row exists is what
        // "derived" is supposed to mean; Canon::post_hash_basis() (see
        // load_tree() above) is the other half — it keeps this field's
        // divergence from ever registering as drift/conflict in the first
        // place, so skipping the write here is consistent with what plan
        // already told the operator would happen.
        if ($this->policy->field_class($front['type'], 'title') === 'derived') {
            unset($fields['post_title']);
        }
        $wpdb->update($wpdb->posts, $fields, ['ID' => $id]);

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
                // task #92: same object_type fallback as Capture's copy of
                // this method — see its comment for the full timing
                // argument (a taxonomy_patterns-matched name landed by
                // Snapshot's OWN phase-1 write this same apply request is
                // never registered in time for get_taxonomy() to see it).
                $objectTypes = $taxObj !== false ? (array) $taxObj->object_type : $this->policy->pattern_object_type($tax);
                if ($objectTypes === null) {
                    $this->warnings[] =
                        "taxonomy '$tax' is in policy scope but not registered on this environment"
                        . " (plugin inactive?) — cannot determine which object type its relationships"
                        . " belong to, so its relationships are skipped for every post and term on apply";
                    continue;
                }
                foreach ($objectTypes as $objectType) {
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
        $bytes = $this->compiled->media_content((string) $front['media']);
        $up = wp_upload_dir(null, false);
        $dst = trailingslashit($up['basedir']) . $front['file'];
        if (!is_file($dst) || hash_file('sha256', $dst) !== hash('sha256', $bytes)) {
            Canon::write_file($dst, $bytes);
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
            // option_name_refs (task #93) — MUST run before the ordinary
            // option_rule($name) lookup below, unconditionally: a token-
            // form key like "woocommerce_flat_rate_{{wc_zone_method:...}}
            // _settings" matches no manifest's exact "options" map entry,
            // so option_rule() would return null -> an empty rule -> the
            // ordinary generic write path below, which would silently
            // upsert a REAL wp_options row whose NAME contains literal
            // "{{...}}" bytes — not a crash, a silent corruption of the
            // target's own options table. Detecting and detokenizing first
            // is what this task's own design review specifically flagged.
            if (str_contains($name, '{{')) {
                if (!preg_match('/\{\{([a-z][a-z0-9_]*):([0-9a-f-]{36})\}\}/', $name, $tm)) {
                    throw new \RuntimeException("duo: option key '$name' contains '{{' but is not a well-formed ref token");
                }
                $realId = $this->tokens->token_to_id($tm[0]);
                $realName = str_replace($tm[0], (string) $realId, $name);
                $rule = $this->policy->match_option_name_ref($realName);
                if ($rule === null) {
                    throw new \RuntimeException(
                        "duo: captured option key '$name' looks token-form (option_name_refs) but matches no "
                        . "declared option_name_refs pattern once detokenized to '$realName' — manifest unpinned "
                        . 'or stale?'
                    );
                }
                $vv = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $this->upsert_option($realName, maybe_serialize($vv));
                continue;
            }
            $rule = $this->policy->option_rule($name) ?? [];
            if (($rule['class'] ?? '') === 'managed') {
                // active_plugins/template/stylesheet (docs/proposals/code-half.md
                // §3.1): writing these via raw $wpdb would make WordPress believe
                // a plugin/theme is active while skipping every activation-hook
                // side effect that makes it actually work — activate_plugin()/
                // switch_theme() exist for exactly that reason. Deploy::run()
                // (`wp duo deploy`) is the ONLY place these are ever reconciled,
                // deliberately outside this canary-armed apply.
                continue;
            }
            if (!empty($rule['sub_keys'])) {
                // DUO-3233: SUB-KEY-LEVEL merge into the live blob, never a
                // whole-value replace — see apply_option_sub_keys()'s own
                // docblock for the full rationale.
                $this->apply_option_sub_keys($name, $v, $rule['sub_keys']);
                continue;
            }
            $this->upsert_option($name, maybe_serialize($this->apply_value($name, $v, $rule)));
        }
    }

    /**
     * Shared apply-direction dispatch, the mirror of Capture::capture_value()
     * — factored out for the identical reason: a sub_keys (DUO-3233) NAMED
     * sub-key's rule is a whole option rule at one nesting level down, so it
     * gets json_refs/key_refs/ref/plain-string detokenization for free, with
     * zero new dispatch logic to keep in sync with the ordinary per-option
     * path.
     */
    private function apply_value(string $ctx, $v, array $rule) {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            return $this->encode_structured($v, $rule);
        }
        if (!empty($rule['ref'])) {
            return $this->tokens->tokens_to_value($v, $rule['ref']);
        }
        if (is_string($v)) {
            return $this->tokens->detokenize_text($v);
        }
        return $v;
    }

    /**
     * sub_keys apply (DUO-3233): SUB-KEY-LEVEL merge into the LIVE blob —
     * never a whole-value replace. Reads the target's own CURRENT value
     * (carrying every key this manifest did NOT carve out — Polylang's own
     * force_lang/rewrite/first_activation/version, populated by the
     * plugin's own activation-time add_option()/admin saves), overlays only
     * the captured, declared-authored sub-keys on top, and writes the
     * merged result back. The excluded remainder survives apply completely
     * untouched, on every environment, every run — this is the mechanism
     * manifests/polylang.json's own notes long documented as missing: "v0's
     * options model classifies a whole option name at once ... there is no
     * way to keep force_lang/default_lang/etc authored while excluding
     * first_activation/version without capturing them too."
     *
     * Absent-live-option case: starts the merge from an empty array
     * (warned) rather than refusing outright. The ordinary case where this
     * would matter — Polylang/Yoast not yet activated on this target — is
     * caught upstream of this code path: spec/repo-format.md's code-half
     * ordering runs `wp duo deploy` (real activate_plugin() calls) before
     * `wp duo apply`, so the owning plugin's own activation-time
     * add_option() has normally already populated this option by the time
     * apply reaches here. A bare `apply` run in isolation (e.g. a test)
     * against a plugin that was never activated is a real, if unusual,
     * situation this still handles honestly rather than refusing: the
     * merged option ends up containing ONLY the declared sub-keys, which is
     * observable (warned) rather than silently incomplete.
     *
     * Deletion: matches ordinary whole-option apply's existing, DOCUMENTED
     * limitation (DUO-3211, open at the time of writing — its own review
     * comment is what asked for sub_keys to compose with its eventual
     * tombstone/deletion semantics "without another format change"): a
     * captured sub-key is upserted; a sub-key that disappears from the repo
     * is never removed from the live blob by this path. Not a regression —
     * whole-option apply has never had delete semantics either — and not
     * attempted here, deliberately, so as not to invent option-deletion
     * semantics ahead of DUO-3211's own design work.
     */
    private function apply_option_sub_keys(string $name, $captured, array $subKeys): void {
        global $wpdb;
        if (!is_array($captured)) {
            throw new \RuntimeException(
                "duo: captured option '$name' declares sub_keys but its repository value is not an object"
            );
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($raw === null) {
            $this->warnings[] = "option $name: no live value to sub-key-merge into — creating it containing ONLY "
                . 'the declared sub-keys (its owning plugin\'s own defaults are absent; expected if that plugin '
                . 'has not been deployed/activated on this target yet)';
            $live = [];
        } else {
            $live = maybe_unserialize($raw);
            if (!is_array($live)) {
                throw new \RuntimeException(
                    "duo: live option '$name' is not array-shaped — cannot sub-key-merge into it (got "
                    . get_debug_type($live) . ')'
                );
            }
        }
        foreach ($captured as $subKey => $subVal) {
            $subRule = $subKeys[$subKey] ?? null;
            if (($subRule['class'] ?? '') !== 'authored') {
                // RepositoryAuthorization::authorize_option_sub_keys() already
                // refuses an undeclared/non-authored captured sub-key before
                // apply ever starts mutating anything — this is a defensive
                // invariant guard against that gate ever being bypassed
                // (e.g. a future internal caller of apply_options() that
                // skips the preflight), not a routinely-reachable branch.
                throw new \RuntimeException(
                    "duo: captured option '$name.$subKey' has no authored sub_keys rule — repository "
                    . 'authorization should have refused this before apply'
                );
            }
            $live[(string) $subKey] = $this->apply_value("$name.$subKey", $subVal, $subRule);
        }
        $this->upsert_option($name, maybe_serialize($live));
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
        if (isset($this->snapshotRowTables()[$type])) {
            Snapshot::delete_row($this->policy, $uuid, $type);
            $this->warnings[] = "deleted $type $uuid";
            return;
        }
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
