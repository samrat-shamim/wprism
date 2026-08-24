<?php
/**
 * `duo-conformance-vector/v1` — record once live, replay forever offline.
 *
 * WHAT A VECTOR IS
 * ----------------
 * `sandbox/conformance/run.sh` proves a round trip against a disposable pair:
 * seed conf1 -> capture -> deploy conf2 -> apply -> recapture conf2 -> the two
 * canonical trees `diff -r` clean (run.sh's "canonical state identical across
 * environments"). That proof costs a pair, and `docs/agents/live-pair-budget.md`
 * allows exactly one pair at a time program-wide with a 6 pair-hour ceiling per
 * wave, so the same proof cannot be re-run per iteration.
 *
 * A vector is what that one run leaves behind, and it is exactly four things:
 *
 *   `rows`      the LIVE rows of every table the adapter declares, at the
 *               moment capture read them — the seeded state as the database
 *               held it, not as a suite author imagined it;
 *   `ledger`    conf1's `duo_map` rows. Identity is the reason this is a
 *               separate field and a hard requirement (`assert_document()`):
 *               `Snapshot::capture()` MINTS a uuid for an unmapped row
 *               (`SnapshotIdentity->identifyRow()`), so a vector without the
 *               recorded ledger replays to a different uuid — and therefore a
 *               different `tables/<t>/<uuid>--<slug>.json` path and different
 *               canonical bytes — on every single run. Recording the ledger is
 *               what makes "byte-identical" a property rather than a wish;
 *   `probe`     WP-2.1's `duo-adapter-probe/v1` document over those same
 *               tables. This is the field that answers `sandbox/tests/lib/README.md`'s
 *               standing objection to a synthetic `information_schema`: the
 *               README refuses one because facts fed from `setColumns()` would
 *               "only be asserting this harness's own bookkeeping". A probe is
 *               not bookkeeping — it is `SHOW COLUMNS` / `SHOW INDEX` /
 *               `information_schema` read off a real pinned plugin version on a
 *               real server, self-hashed, with `authority: false` stamped in it
 *               (`agent/src/Adapter/AdapterProbe.php:114-126`). `seed()` feeds
 *               FakeWpdb's `setColumns()`/`setPrimaryKey()`/`setUniqueKey()`
 *               from it, so the replay's schema is a RECORDING, and
 *               `TableSchema::live_column_types()` — which `ensureRow()` calls
 *               before every typed insert — sees the target's real types;
 *   `state` +   conf1's captured canonical tree and conf2's recapture. Live
 *   `recapture` those two were proven byte-identical; the vector keeps both so
 *               the replay can check each leg against the tree that leg
 *               actually produced, instead of checking one tree twice.
 *
 * THE VERDICT WORD IS A DISTINCT, WEAKER WORD
 * -------------------------------------------
 * The danger a replayable vector creates is that a green replay gets quoted as
 * if a pair had run. So `replay()` never emits the live verdict word. The
 * recorder stamps `recorded.verdict = LIVE_VERDICT` ('conformance_verified')
 * into a vector only after run.sh's own acceptance passed; `replay()` returns
 * REPLAY_VERDICT ('vector_replayed') or REPLAY_REFUSED, and every envelope
 * carries `verdict_is_not`, `verdict_note` and the `deferred()` rows — on a
 * PASS as much as on a failure, which is the discipline `duo manifest-validate`
 * uses when it stamps `status: deferred` rows on every run
 * (`cli/src/Adapter/AdapterCatalog.php:193-195`) so silence cannot read as
 * verified.
 *
 * WHAT REPLAY ACTUALLY RUNS
 * -------------------------
 * The real, unmodified engine on both legs — `Policy::from_snapshot()` over
 * FrozenPolicy's fail-closed `duo-policy-snapshot/v6` envelope, then
 * `Snapshot::capture()` for the capture leg and `Snapshot::ensure_row()` +
 * `Snapshot::finalize_row()` (the two calls `AuthoredTransactionExecutor.php:172,203`
 * makes) into a SECOND, EMPTY FakeWpdb for the apply leg, then capture again.
 * Nothing here reimplements a materializer. A manifest edit that changes what
 * canonical holds therefore changes the replayed bytes and is caught.
 *
 * Needs the agent runtime, exactly like `frozen_policy.php`: the suite requires
 * Canon/Policy/Snapshot/Tokens and this file references them by name.
 */
declare(strict_types=1);

namespace DuoTest;

final class ConformanceVector
{
    /** The wire generation of a recorded vector. */
    public const FORMAT = 'duo-conformance-vector/v1';

    /** The probe generation `probe` must carry (`AdapterProbe::FORMAT`). */
    public const PROBE_FORMAT = 'duo-adapter-probe/v1';

    /**
     * The verdict a LIVE `sandbox/conformance/run.sh` sweep earns, stamped
     * into a vector by the recorder because the recorder only runs after that
     * sweep's own acceptance passed. Nothing in this class ever RETURNS it.
     */
    public const LIVE_VERDICT = 'conformance_verified';

    /** The strictly weaker verdict a successful offline replay earns. */
    public const REPLAY_VERDICT = 'vector_replayed';

    /** The verdict a replay that did not reproduce the recorded bytes earns. */
    public const REPLAY_REFUSED = 'vector_replay_refused';

    /**
     * `duo_map`'s shape, from Ledger's own `CREATE TABLE`
     * (`agent/src/Repository/Ledger.php:81-88`). Duo's ledger is a SHIPPED
     * fact, not a site fact, which is why it comes from here and not from the
     * probe: a probe describes the ADAPTER's declared tables, and stamping
     * Duo's own schema into it would let a site's answer redefine the ledger.
     */
    private const LEDGER_MAP_COLUMNS = [
        'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(64)',
        'local_id' => 'bigint(20) unsigned',
        'uuid' => 'char(36)',
    ];

    /**
     * Assemble and self-hash a vector. `$parts` supplies every field except
     * `format` and `vector_hash`; a missing or unknown one is refused here
     * rather than at replay, so a recorder that stops emitting a field fails
     * on the pair that produced it instead of six weeks later.
     *
     * @param array<string,mixed> $parts
     * @return array<string,mixed>
     */
    public static function document(array $parts): array
    {
        $required = ['ledger', 'manifest', 'probe', 'recapture', 'recorded', 'rows', 'state'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $parts)) {
                throw new \RuntimeException("duo: conformance vector is missing '$key'");
            }
        }
        foreach (array_keys($parts) as $key) {
            if (!in_array((string) $key, $required, true)) {
                throw new \RuntimeException("duo: conformance vector carries an unknown field '$key'");
            }
        }
        $document = ['format' => self::FORMAT] + $parts;
        ksort($document, SORT_STRING);
        $document['vector_hash'] = self::hash_document($document);
        return $document;
    }

    /** Canonical self-hash, the basis a replay recomputes before consuming. */
    public static function hash_document(array $document): string
    {
        unset($document['vector_hash']);
        return 'sha256:' . hash('sha256', \Duo\Canon::encode($document));
    }

    /**
     * Refuse a vector nothing could honestly replay. Each throw names the
     * property that is missing, because a vector is evidence and evidence that
     * silently degrades is worse than no evidence.
     */
    public static function assert_document(array $document): void
    {
        if (($document['format'] ?? '') !== self::FORMAT) {
            throw new \RuntimeException(
                'duo: not a ' . self::FORMAT . ' document (found \'' . (string) ($document['format'] ?? '') . '\')'
            );
        }
        if (($document['vector_hash'] ?? '') !== self::hash_document($document)) {
            throw new \RuntimeException('duo: conformance vector self-hash does not match its bytes');
        }
        if (($document['probe']['format'] ?? '') !== self::PROBE_FORMAT) {
            throw new \RuntimeException(
                'duo: conformance vector carries no ' . self::PROBE_FORMAT . ' probe; without one the replay '
                . 'would have to invent column types, which is the synthetic information_schema '
                . 'sandbox/tests/lib/README.md refuses'
            );
        }
        if (!empty($document['probe']['authority'])) {
            throw new \RuntimeException(
                'duo: the recorded probe claims authority; AdapterProbe stamps authority:false '
                . '(agent/src/Adapter/AdapterProbe.php:115) and a vector never upgrades that'
            );
        }
        if (($document['recorded']['verdict'] ?? '') !== self::LIVE_VERDICT) {
            throw new \RuntimeException(
                'duo: conformance vector was not recorded under the live verdict \'' . self::LIVE_VERDICT
                . '\'; only a sweep whose own acceptance passed may leave a vector behind'
            );
        }
        if (!is_array($document['ledger']['duo_map'] ?? null) || $document['ledger']['duo_map'] === []) {
            throw new \RuntimeException(
                'duo: conformance vector records no duo_map rows; capture MINTS a uuid for an unmapped row, so '
                . 'the replay would produce different canonical paths and bytes on every run'
            );
        }
        if (($document['state'] ?? []) === []) {
            throw new \RuntimeException('duo: conformance vector records an empty canonical state tree');
        }
        if (($document['state'] ?? null) !== ($document['recapture'] ?? null)) {
            throw new \RuntimeException(
                'duo: conformance vector records a state tree and a recapture that already disagree; the live '
                . 'sweep asserts they are byte-identical before the recorder runs, so this vector did not come '
                . 'from a passing round trip'
            );
        }
    }

    /**
     * Install a FakeWpdb holding the recorded rows and the recorded schema.
     *
     * `$withRows = false` gives the APPLY leg its empty target: the same
     * schema, the same declared tables, no rows and no identity — which is
     * what conf2 is when `duo apply` starts.
     */
    public static function seed(array $document, bool $withRows = true): FakeWpdb
    {
        $wpdb = FakeWpdb::install();

        foreach ((array) ($document['probe']['tables'] ?? []) as $table => $facts) {
            $table = (string) $table;
            if (empty($facts['present'])) {
                // present:false is a real recorded answer (AdapterProbe.php:146-148):
                // the target had no such table, so the replay must not have one
                // either — an invented empty table would let a declaration the
                // live target refused look captured.
                continue;
            }
            $rows = $withRows ? (array) ($document['rows'][$table] ?? []) : [];
            $wpdb->seedTable('wp_' . $table, array_values($rows));
            $columns = [];
            foreach ((array) ($facts['columns'] ?? []) as $column => $shape) {
                $columns[(string) $column] = (string) ($shape['type'] ?? '');
            }
            if ($columns !== []) {
                $wpdb->setColumns('wp_' . $table, $columns);
            }
            $primary = array_values((array) ($facts['primary_key'] ?? []));
            if (count($primary) === 1) {
                // A single-column PK is the only one FakeWpdb's auto-increment
                // bookkeeping can carry, and it is the only one a typed
                // authored_snapshot table may declare (`pk` is one column).
                $wpdb->setAutoIncrement('wp_' . $table, self::next_id($rows, (string) $primary[0]), (string) $primary[0]);
            }
            foreach ((array) ($facts['unique_keys'] ?? []) as $columnsOfKey) {
                $wpdb->setUniqueKey('wp_' . $table, array_values((array) $columnsOfKey));
            }
        }

        $wpdb->seedTable('wp_duo_map', $withRows ? array_values((array) $document['ledger']['duo_map']) : []);
        $wpdb->setColumns('wp_duo_map', self::LEDGER_MAP_COLUMNS);
        // Both of Ledger's declared keys: `Ledger::set()` writes ON DUPLICATE
        // KEY UPDATE, and FakeWpdb refuses that statement outright unless the
        // colliding key is declared (FakeWpdb.php findUniqueConflict()).
        $wpdb->setUniqueKey('wp_duo_map', ['id_kind', 'local_id']);
        $wpdb->setUniqueKey('wp_duo_map', ['uuid', 'id_kind']);

        $wpdb->seedTable('wp_duo_state', []);
        $wpdb->setColumns('wp_duo_state', [
            'content_hash' => 'char(64)',
            'entity_type' => 'varchar(64)',
            'uuid' => 'varchar(64)',
        ]);
        $wpdb->setUniqueKey('wp_duo_state', ['uuid']);

        $wpdb->seedTable('wp_duo_kv', []);
        $wpdb->setColumns('wp_duo_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
        $wpdb->setUniqueKey('wp_duo_kv', ['k']);

        return $wpdb;
    }

    /**
     * The adapter, loaded the way a deployed site loads it: through
     * `Policy::from_snapshot()` over FrozenPolicy's fail-closed v6 envelope,
     * which publishes the manifest bytes into a scratch library so the
     * `duo-adapter-sources/v2` membership proof has something real to compare.
     */
    public static function policy(array $manifest): \Duo\Policy
    {
        return \Duo\Policy::from_snapshot(
            FrozenPolicy::envelope([$manifest], FrozenPolicy::site([$manifest]))
        );
    }

    /**
     * One capture leg through the real engine, flattened to the
     * `path => canonical bytes` shape a `state/` tree has on disk.
     *
     * @return array<string,string>
     */
    public static function capture_tree(\Duo\Policy $policy): array
    {
        $tree = [];
        foreach (\Duo\Snapshot::capture($policy, new \Duo\Tokens(), true) as $entity) {
            $tree[(string) $entity['path']] = (string) $entity['content'];
        }
        ksort($tree, SORT_STRING);
        return $tree;
    }

    /**
     * Replay a recorded vector offline and return the verdict envelope.
     *
     * `$manifest` overrides the recorded adapter — that is how a suite proves
     * a manifest edit is CAUGHT: the recorded expectation stays fixed while
     * the library under test moves, which is exactly the fleet situation
     * AGENTS.md rule 2 describes (one byte under `manifests/` is a
     * fleet-visible change).
     *
     * @return array<string,mixed>
     */
    public static function replay(array $document, ?array $manifest = null): array
    {
        self::assert_document($document);
        $manifest = $manifest ?? (array) $document['manifest'];
        $mismatches = [];

        // Leg 1 — capture: the recorded rows, read by the real capture engine,
        // must reproduce the tree conf1 published.
        self::reset_target();
        self::seed($document, true);
        $captured = self::capture_tree(self::policy($manifest));
        $expectedState = self::string_map($document['state']);
        $mismatches = array_merge($mismatches, self::diff('state', $expectedState, $captured));

        // Leg 2 — apply + recapture: the SAME canonical tree, materialized into
        // an empty target through the two calls apply makes, must recapture to
        // the tree conf2 published. Replaying leg 1's OUTPUT here rather than
        // the recorded tree is deliberate: apply consumes what capture just
        // produced, so a leg-1 drift cannot be laundered by leg 2 reading the
        // fixture instead.
        self::reset_target();
        self::seed($document, false);
        $policy = self::policy($manifest);
        $tokens = new \Duo\Tokens();
        $entities = [];
        foreach ($captured as $path => $content) {
            $front = \Duo\Canon::decode($content);
            $entities[] = [
                'content' => $content,
                'data' => $front,
                'path' => $path,
                'type' => (string) $front['table'],
                'uuid' => (string) $front['uuid'],
            ];
        }
        foreach ($entities as $entity) {
            \Duo\Snapshot::ensure_row($policy, $entity);
        }
        foreach ($entities as $entity) {
            \Duo\Snapshot::finalize_row($policy, $tokens, $entity);
        }
        $recaptured = self::capture_tree(self::policy($manifest));
        $mismatches = array_merge(
            $mismatches,
            self::diff('recapture', self::string_map($document['recapture']), $recaptured)
        );

        return [
            'deferred' => self::deferred(),
            'format' => self::FORMAT,
            'mismatches' => $mismatches,
            'replayed' => $mismatches === [],
            'vector_hash' => (string) $document['vector_hash'],
            'verdict' => $mismatches === [] ? self::REPLAY_VERDICT : self::REPLAY_REFUSED,
            'verdict_is_not' => self::LIVE_VERDICT,
            'verdict_note' => 'a replayed vector reproduces recorded bytes through the real engine on a fake '
                . '$wpdb; it boots no WordPress, runs no plugin code, performs no deploy and renders no page, '
                . 'so it can never carry the live verdict \'' . self::LIVE_VERDICT . '\'',
        ];
    }

    /**
     * What a replay does NOT establish, stamped on every envelope — pass
     * included. Same shape and same reason as
     * `AdapterCatalog::deferred()`'s `status: deferred` rows.
     *
     * @return list<array<string,string>>
     */
    public static function deferred(): array
    {
        $rows = [
            [
                'surface' => 'the plugin\'s own PHP',
                'check' => 'conformance/seeds/<name>.sh authoring through plugin APIs',
                'why' => 'a replay seeds ROWS the plugin once wrote; it never runs the plugin, so a plugin '
                    . 'version whose writes changed shape produces a vector that still replays green until it '
                    . 'is re-recorded. The version window the recording was taken in is in `recorded`',
            ],
            [
                'surface' => 'deploy: activation and theme reconciliation',
                'check' => 'run.sh\'s "conf2\'s activation/theme state matches canonical, from deploy alone"',
                'why' => 'Deploy.php calls real activate_plugin()/switch_theme(), and a plugin activation hook '
                    . 'can mint content with no natural key (Snapshot.php\'s "mapped" identity mode). None of '
                    . 'that exists offline',
            ],
            [
                'surface' => 'render-level acceptance',
                'check' => 'conformance/checks/<name>.sh',
                'why' => 'run.sh\'s own docblock says those hooks exist for "render-level acceptance a '
                    . 'byte-diff can\'t see" — two environments can encode the same WRONG bytes. A replay is a '
                    . 'byte comparison and is blind to exactly what those hooks were added to catch',
            ],
            [
                'surface' => 'the suspicious-ref lint gate',
                'check' => 'wp duo lint --repo=/siterepo --format=json (run.sh\'s hard gate)',
                'why' => 'lint scans the captured tree for ref-shaped values with no declared rewrite path; the '
                    . 'recorded tree passed it once, on the pair. A replay re-derives that same tree and learns '
                    . 'nothing new about it',
            ],
            [
                'surface' => 'live schema truth beyond the recorded probe',
                'check' => 'Ledger::assert_read_only_schema() / Snapshot::assert_all_mapped_rows_managed()',
                'why' => 'FakeWpdb refuses information_schema reads, LEFT JOINs and SHOW INDEX by design '
                    . '(sandbox/tests/lib/README.md). The probe supplies column types, primary key and unique '
                    . 'keys as RECORDED facts; every other schema-truth path stays a live certification',
            ],
        ];
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * Both legs start from a clean WordPress stub store: `wp_upload_dir()` and
     * `get_option('home')` feed Tokens' URL rewriting, and a leaked option
     * from a previous leg would silently change what capture tokenizes.
     */
    private static function reset_target(): void
    {
        WpStore::reset()->seedOptions(['home' => 'https://duo-vector.invalid']);
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,string> $actual
     * @return list<string>
     */
    private static function diff(string $leg, array $expected, array $actual): array
    {
        $out = [];
        foreach ($expected as $path => $content) {
            if (!array_key_exists($path, $actual)) {
                $out[] = "$leg: recorded path '$path' was not produced";
                continue;
            }
            if ($actual[$path] !== $content) {
                $out[] = "$leg: '$path' bytes differ from the recording";
            }
        }
        foreach (array_keys($actual) as $path) {
            if (!array_key_exists($path, $expected)) {
                $out[] = "$leg: '$path' was produced but is in no recording";
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param mixed $tree
     * @return array<string,string>
     */
    private static function string_map($tree): array
    {
        $out = [];
        foreach ((array) $tree as $path => $content) {
            $out[(string) $path] = (string) $content;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function next_id(array $rows, string $primaryKey): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row[$primaryKey] ?? 0));
        }
        return $max + 1;
    }
}
