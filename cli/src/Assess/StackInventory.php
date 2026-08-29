<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use WPrism\CommandRefusalException;

/**
 * Section 1 of `wprism assess` — the stack, and the authority WPrism actually has
 * (round-3 MUP §2.1, §4.1).
 *
 * Two blocks are produced here and nothing else.
 *
 * **`stack()`** is `wprism-assess-inventory/v1`'s `target` block, passed
 * through verbatim after its closed key set is proved. It is deliberately a
 * pass-through rather than a re-derivation: the versions an operator reads
 * in an assessment must be the ones the target itself reported, and a host
 * that reformatted them would be a second, quieter source of truth. The
 * validation exists because `wprism-assess-report/v1` re-publishes this block
 * under a closed schema (`ContractProposal::validateAssessReport()`), so a
 * malformed inventory has to fail here — where the environment is still
 * named — rather than at proposal time.
 *
 * **`authority()`** is the honest answer to "what access does WPrism have on
 * this site, right now, for this command". MUP §2.1's human line reads
 * `authority: ssh deploy@prod · read-only for this command · repo /srv/site`,
 * and every fact behind it is composed by `AssessCommand` from a different
 * module: the transport describes itself, `Doctor` proves reachability,
 * `BootstrapEligibility`/`Init` say whether the site could still be adopted
 * or initialized, and the adapter catalog says which of the three adapter
 * sources this process could see. This class holds none of those
 * collaborators — it takes their results as data, which is what keeps
 * `cli/src/Assess/` at the engine layer with no edge to Onboarding,
 * Environment or Adapter (module map rule 9).
 *
 * The `authority` block is NOT key-closed by the contract consumer
 * (`ContractProposal` reads none of it, by design), but it is key-closed
 * *here*: assess owns the shape, and a section an operator uses to decide
 * whether to trust an assessment should not grow keys by accident.
 *
 * One placement note that is a consequence, not a preference: the installed
 * plugin/theme/attachment COUNTS live under `authority.installed` rather
 * than beside the versions in `target`. `wprism-assess-report/v1`'s top level
 * is a closed key set of exactly nine keys and `target` is the inventory's
 * block verbatim, so there is no third place for a count to go. They are
 * counts of what this environment holds, which is the same question the
 * rest of this block answers.
 */
final class StackInventory {
    /** `wprism-assess-inventory/v1`'s target block, exactly. */
    public const TARGET_KEYS = ['wordpress', 'php', 'database', 'site_mode', 'home', 'siteurl'];

    public const DATABASE_KEYS = ['engine', 'version'];

    /** The only access this command ever takes. Printed, not inferred. */
    public const ACCESS = 'read-only for this command';

    public const SITE_MODES = ['single-site', 'multisite'];

    /**
     * MUP §4.6 binds the generated document too. The identity listings below
     * are bounded and carry an explicit `truncated` flag beside the exact
     * counts in `installed`, so a very large site produces a bounded report
     * rather than an unbounded one, and never a wrong count.
     */
    public const MAX_IDENTITY_ROWS = 200;

    /**
     * The stack block, proved and passed through.
     *
     * @param array<string,mixed> $inventory a `wprism-assess-inventory/v1` document
     * @return array<string,mixed> the inventory's `target`, byte-for-byte
     */
    public static function stack(array $inventory): array {
        $target = $inventory['target'] ?? null;
        if (!is_array($target) || array_is_list($target)) {
            throw self::refuse('the target inventory carries no stack block');
        }
        $keys = array_keys($target);
        sort($keys, SORT_STRING);
        $expected = self::TARGET_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw self::refuse('the target inventory stack block does not carry the expected fields');
        }
        foreach (['wordpress', 'php', 'site_mode', 'home', 'siteurl'] as $key) {
            if (!is_string($target[$key]) || $target[$key] === '') {
                throw self::refuse("the target inventory stack field '$key' is not a non-empty string");
            }
        }
        if (!in_array($target['site_mode'], self::SITE_MODES, true)) {
            throw self::refuse('the target inventory reports an unknown site mode');
        }
        $database = $target['database'];
        if (!is_array($database) || array_is_list($database)) {
            throw self::refuse('the target inventory carries no database block');
        }
        $databaseKeys = array_keys($database);
        sort($databaseKeys, SORT_STRING);
        if ($databaseKeys !== self::DATABASE_KEYS) {
            throw self::refuse('the target inventory database block does not carry engine and version');
        }
        foreach (self::DATABASE_KEYS as $key) {
            if (!is_string($database[$key]) || $database[$key] === '') {
                throw self::refuse("the target inventory database field '$key' is not a non-empty string");
            }
        }

        return $target;
    }

    /**
     * The authority block.
     *
     * @param array<string,mixed> $facts closed key set, all supplied by
     *        `AssessCommand`:
     *        `environment`, `driver` (the driver id), `transport` (the
     *        driver's own one-line description), `repo_path` (the TARGET's
     *        repository path), `site_repo` (the LOCAL site repository the
     *        proposal is written into), `doctor` (a `Doctor::run()` result),
     *        `bootstrap` (a probe result or null), `init_probe` (a probe
     *        result or null), `catalog` (the host `wprism-adapter-catalog/v2`
     *        document or null) and `inventory` (the assess inventory).
     * @return array<string,mixed>
     */
    public static function authority(array $facts): array {
        self::requireKeys($facts, [
            'environment', 'driver', 'transport', 'repo_path', 'site_repo',
            'doctor', 'bootstrap', 'init_probe', 'catalog', 'inventory',
        ]);
        /** @var array<string,mixed> $inventory */
        $inventory = $facts['inventory'];

        return [
            'access' => self::ACCESS,
            'environment' => (string) $facts['environment'],
            'driver' => (string) $facts['driver'],
            'transport' => (string) $facts['transport'],
            'repo_path' => (string) $facts['repo_path'],
            'site_repo' => (string) $facts['site_repo'],
            'doctor' => self::doctorBlock(is_array($facts['doctor']) ? $facts['doctor'] : []),
            'bootstrap' => is_array($facts['bootstrap']) ? $facts['bootstrap'] : null,
            'init_probe' => is_array($facts['init_probe']) ? $facts['init_probe'] : null,
            'installed' => self::installed($inventory),
            'code' => self::code($inventory),
            'adapters' => self::adapters($inventory, is_array($facts['catalog']) ? $facts['catalog'] : null),
            // The agent's `adoption` block, present only when the repository
            // is an adoption seed and the inventory was projected against the
            // init proposal (T7 grind A3): a reader of the report — human or
            // machine — must be able to tell that preview from a repository
            // in force. Null for an init-owned repository.
            'adoption' => is_array($inventory['adoption'] ?? null) ? $inventory['adoption'] : null,
        ];
    }

    /**
     * Doctor's own verdict, reduced to labels and counts.
     *
     * The detail strings are deliberately dropped: they carry target paths,
     * ssh destinations and wp-cli output, and MUP §5.2's rule is that a
     * human view prints an internal identifier only when a documented
     * command consumes it. `wprism doctor <env>` is that command, and it is
     * named in the remediation.
     *
     * @param array{ok?:bool,checks?:list<array{label:string,ok:bool,detail:string,advisory?:bool}>} $doctor
     * @return array<string,mixed>
     */
    private static function doctorBlock(array $doctor): array {
        $checks = is_array($doctor['checks'] ?? null) ? $doctor['checks'] : [];
        $failed = [];
        $advisory = [];
        $passed = 0;
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $label = (string) ($check['label'] ?? '');
            if (($check['ok'] ?? false) === true) {
                $passed++;
                continue;
            }
            if (($check['advisory'] ?? false) === true) {
                $advisory[] = $label;
                continue;
            }
            $failed[] = $label;
        }

        return [
            'ok' => ($doctor['ok'] ?? false) === true,
            'checks_total' => count($checks),
            'checks_passed' => $passed,
            'failed' => $failed,
            'advisory' => $advisory,
        ];
    }

    /**
     * The installed-plugin rows of a `wprism-assess-inventory/v1` document.
     *
     * `plugins` is a bare list of `{basename,name,version,active}` rows and
     * stays one: two of this method's three consumers feed the assess_digest
     * (`code()`'s identity rows) or the proposed contract's stack ranges
     * (`AssessReport::proposalSeed()`), and T6 deliberately did not move a
     * shape those depend on. The unmanaged-plugin list T6 §3.6 adds is a
     * SIBLING top-level key instead — see pluginsWithoutAdapter().
     *
     * One reader all the same, so the three call sites share one definition
     * of "a plugin row" and a malformed entry is skipped in one place.
     *
     * @param array<string,mixed> $inventory
     * @return list<array<string,mixed>>
     */
    public static function pluginRows(array $inventory): array {
        $plugins = $inventory['plugins'] ?? null;
        if (!is_array($plugins) || !array_is_list($plugins)) {
            return [];
        }
        $out = [];
        foreach ($plugins as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Active plugins no PINNED manifest declares (T6 §3.6).
     *
     * The target decides this, not the host: only the target can see
     * WP_PLUGIN_DIR and the active list, and only the engine knows which
     * manifests its pins resolved. So this reads a published list rather than
     * deriving one — a host-side derivation would be a second, weaker answer
     * that disagreed with `wprism init`'s refusal on exactly the sites where it
     * mattered.
     *
     * `plugins_without_adapter[]` rows are `{basename, file, slug}` — all
     * three identity parts published, so nothing is split or guessed here,
     * and `basename` carries the same meaning it does on an ordinary
     * `plugins[]` row. Note the judgement is against PINNED manifests: an
     * adapter that is installed but unpinned leaves the plugin on this list,
     * which is correct (an unpinned adapter governs nothing) and is reported
     * separately by `adapter_survey` with its own certification word.
     *
     * A row missing `slug` is skipped rather than reconstructed. The agent
     * publishes all three parts; a row without them is a malformed document,
     * and inventing a slug from a basename that was not published would put a
     * surface id into the assessment that no target ever named.
     *
     * @param array<string,mixed> $inventory
     * @return list<array{slug:string,file:string,basename:string}>
     */
    public static function pluginsWithoutAdapter(array $inventory): array {
        $raw = $inventory['plugins_without_adapter'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
            $file = is_string($row['file'] ?? null) ? $row['file'] : '';
            $basename = is_string($row['basename'] ?? null) ? $row['basename'] : '';
            if ($slug === '' || $file === '' || $basename === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'file' => $file, 'basename' => $basename];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['basename'], $b['basename']));

        return $out;
    }

    /**
     * What this environment holds, in counts.
     *
     * `media_bytes` is reported exactly as the inventory reports it, which
     * in this round is always null: attachment size is a filesystem fact
     * with no bounded database oracle behind it, and inventing one from
     * `_wp_attachment_metadata` would be wrong for every non-image and stale
     * after any plugin rewrite (agent `AssessInventory`'s own decision).
     * A null here means "not measured", never "zero".
     *
     * @param array<string,mixed> $inventory
     * @return array<string,mixed>
     */
    private static function installed(array $inventory): array {
        $plugins = self::pluginRows($inventory);
        $themes = is_array($inventory['themes'] ?? null) ? $inventory['themes'] : [];
        $media = is_array($inventory['media'] ?? null) ? $inventory['media'] : [];

        return [
            'plugins' => count($plugins),
            'plugins_active' => count(array_filter(
                $plugins,
                static fn ($row): bool => is_array($row) && ($row['active'] ?? false) === true
            )),
            'themes' => count($themes),
            'themes_active' => count(array_filter(
                $themes,
                static fn ($row): bool => is_array($row) && ($row['active'] ?? false) === true
            )),
            'attachments' => is_int($media['count'] ?? null) ? $media['count'] : 0,
            'media_bytes' => is_int($media['bytes'] ?? null) ? $media['bytes'] : null,
        ];
    }

    /**
     * The installed code, by identity and version.
     *
     * This block exists for one reason and it is worth stating: the assess
     * report's `assess_digest` is what `wprism contract accept` binds a review
     * to, and a review is stale exactly when the site it described has
     * moved. Plugin and theme versions are the fastest-moving facts on a
     * WordPress site AND the facts the proposed contract's `stack.plugins`
     * ranges are derived from — so a report that carried only counts would
     * let a plugin update slip past a reviewed contract that pins its
     * version. Counts are not identity.
     *
     * Names and versions only. No paths, no headers, no author strings.
     *
     * @param array<string,mixed> $inventory
     * @return array<string,mixed>
     */
    private static function code(array $inventory): array {
        $plugins = [];
        foreach (self::pluginRows($inventory) as $plugin) {
            if (!is_string($plugin['basename'] ?? null)) {
                continue;
            }
            $plugins[] = [
                'basename' => $plugin['basename'],
                'version' => (string) ($plugin['version'] ?? ''),
                'active' => ($plugin['active'] ?? false) === true,
            ];
        }
        $themes = [];
        foreach (($inventory['themes'] ?? []) as $theme) {
            if (!is_array($theme) || !is_string($theme['stylesheet'] ?? null)) {
                continue;
            }
            $themes[] = [
                'stylesheet' => $theme['stylesheet'],
                'version' => (string) ($theme['version'] ?? ''),
                'active' => ($theme['active'] ?? false) === true,
            ];
        }

        return [
            'plugins' => array_slice($plugins, 0, self::MAX_IDENTITY_ROWS),
            'plugins_truncated' => count($plugins) > self::MAX_IDENTITY_ROWS,
            'themes' => array_slice($themes, 0, self::MAX_IDENTITY_ROWS),
            'themes_truncated' => count($themes) > self::MAX_IDENTITY_ROWS,
        ];
    }

    /**
     * The three adapter sources, and which of them this assessment reached.
     *
     * The host catalog can see the shipped library and (with a repository)
     * its `adapters/` overlay; the third source — one `wprism-adapter.json` at
     * the root of each active plugin — lives in `WP_PLUGIN_DIR` and is only
     * reachable on the target, which is why the inventory carries the
     * target's own survey. Reporting both, with each source marked scanned
     * or not, is what stops an empty result reading as "nothing is
     * installed".
     *
     * @param array<string,mixed> $inventory
     * @param array<string,mixed>|null $catalog a `wprism-adapter-catalog/v2` document
     * @return array<string,mixed>
     */
    private static function adapters(array $inventory, ?array $catalog): array {
        $pins = $inventory['policy']['manifests'] ?? [];
        $sources = [];
        foreach (($catalog['sources'] ?? []) as $source) {
            if (!is_array($source) || !is_string($source['source'] ?? null)) {
                continue;
            }
            $sources[] = [
                'source' => $source['source'],
                'scanned' => ($source['scanned'] ?? false) === true,
            ];
        }
        $survey = $inventory['adapter_survey'] ?? null;
        $surveyBlock = null;
        if (is_array($survey)) {
            $surveyBlock = [
                'installed' => count(is_array($survey['adapters'] ?? null) ? $survey['adapters'] : []),
                'refusals' => count(is_array($survey['refusals'] ?? null) ? $survey['refusals'] : []),
            ];
        }

        $pinRows = [];
        foreach (is_array($pins) ? $pins : [] as $pin) {
            if (!is_array($pin) || !is_string($pin['name'] ?? null)) {
                continue;
            }
            // The pinned adapter set and its content digests, for the same
            // reason `code()` exists: a moved adapter digest is a different
            // policy, and a reviewed contract that pins the old one must
            // not accept against the new one.
            $pinRows[] = [
                'name' => $pin['name'],
                'source' => is_string($pin['source'] ?? null) ? $pin['source'] : 'shipped',
                'adapter_digest' => is_string($pin['adapter_digest'] ?? null) ? $pin['adapter_digest'] : null,
                'status' => is_string($pin['status'] ?? null) ? $pin['status'] : null,
            ];
        }
        usort($pinRows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'pinned' => is_array($pins) ? count($pins) : 0,
            'pins' => array_slice($pinRows, 0, self::MAX_IDENTITY_ROWS),
            'host_sources' => $sources,
            'target_survey' => $surveyBlock,
            'target_survey_unavailable_reason' => is_string($inventory['adapter_survey_reason'] ?? null)
                ? $inventory['adapter_survey_reason']
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $facts
     * @param list<string> $keys
     */
    private static function requireKeys(array $facts, array $keys): void {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $facts)) {
                throw self::refuse("the assessment authority facts are missing '$key'");
            }
        }
        foreach (array_keys($facts) as $key) {
            if (!in_array((string) $key, $keys, true)) {
                throw self::refuse("the assessment authority facts carry the unknown key '" . (string) $key . "'");
            }
        }
    }

    private static function refuse(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'assess_inventory_malformed',
            $message,
            'update the target agent so `wp wprism assess-inventory` emits the current document, then rerun assess'
        );
    }
}
