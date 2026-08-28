<?php
namespace Duo;

/**
 * Closed, value-redacted evidence export for the adapter authoring loop.
 *
 * This is deliberately not another capture, plan, or draft-evidence input.
 * It reads the existing journal, pending-review, adapter-source, and
 * capability paths only, then projects their facts into a document a host can
 * validate without trusting target-local paths, values, ids, titles, hook
 * stacks, SQL, or exception prose.  The normal WordPress/plugin bootstrap is
 * intentionally still in force: plugin-bundled adapters and runtime provider
 * registrations are facts this command must be able to see.  Consequently the
 * guarantee is narrower and explicit in `deferred`: after observer entry Duo
 * performs no explicit mutation or provider action, but normal plugin/provider
 * registration and capability negotiation remain enabled, so third-party code
 * can have side effects before or during evidence collection. It does not
 * claim whole-process immutability.
 */
final class AdapterObservation {
    public const FORMAT = 'duo-adapter-observation/v1';
    public const REDACTION = 'values_omitted';

    private const SOURCES = [AdapterSources::SHIPPED, AdapterSources::SITE, AdapterSources::PLUGIN];
    private const TRUST_TIERS = [
        AdapterSources::TIER_DECLARATIVE,
        AdapterSources::TIER_NATIVE_ACTION,
        AdapterSources::TIER_PLUGIN_PROVIDER,
        AdapterSources::TIER_COMPATIBILITY_SHIM,
    ];
    private const JOURNAL_SURFACES = ['admin', 'ajax', 'cli', 'cron', 'front', 'rest'];
    private const JOURNAL_CAPABILITIES = ['none', 'edit_posts', 'manage_options'];
    private const JOURNAL_PROPOSALS = ['authored', 'review', 'runtime'];
    private const JOURNAL_VERDICTS = ['abstain', 'agree', 'disagree', 'managed', 'unclassified'];
    private const PENDING_SECTIONS = ['options', 'post_meta', 'scope', 'table_meta', 'term_meta', 'user_meta', 'widgets'];
    private const PENDING_PROPOSALS = ['authored', 'runtime'];
    /**
     * Closed labels, not copied values. Pending currently supplies the human
     * labels returned by Secrets::hard_match(); normalize them here so the
     * public document never needs to accept arbitrary prose from a future
     * detector implementation.
     */
    private const PENDING_SECRETS = [
        'hard:aws_key',
        'hard:github_token',
        'hard:jwt',
        'hard:private_key',
        'hard:slack_token',
        'hard:stripe_key',
        'suspicious',
    ];
    private const PENDING_SHAPES = ['array', 'bool', 'float', 'int', 'null', 'object', 'other', 'string'];
    private const GRAMMAR = [AdapterSources::GRAMMAR_OK, AdapterSources::GRAMMAR_ERROR, AdapterSources::GRAMMAR_BLOCKED];
    /**
     * `site_signed` joined in round-3 T6: a valid certificate under a key in
     * the SITE's own adapters/authorities.json with an exact pin. It is a
     * closed enum, so a word missing here is not a cosmetic gap — the
     * projection refuses the whole observation rather than emit an
     * unrecognised vocabulary, which is exactly the intent for a word nobody
     * downstream knows how to read.
     *
     * `reviewer_signed` joined at gate G4 (§ v3.16, WP-5.2), and it had to
     * join here in the SAME change that let `AdapterSources` mint it: the
     * derivation and this enum are the emitting half of one wire, so a word the
     * engine can produce and this list does not carry would refuse the target's
     * own observation of itself.
     */
    private const CERTIFICATIONS = [
        'certification_unjudged', 'registry', 'reviewer_signed', 'signed_unpinned', 'site_signed',
        'third_party_signed', 'uncertified',
    ];
    private const CLAIM_STATUSES = ['certified', 'excluded', 'experimental', 'uncertified', 'unsupported'];
    private const VERDICTS = ['blocked', 'certified'];
    private const BLOCKER_STATUSES = ['blocked', 'unreviewed', 'unsupported'];

    /**
     * Read the target's existing evidence paths without installing or repairing
     * any prerequisite.  In particular this never calls Ledger::ensure(): a
     * missing journal is an honest refusal, not a reason to create state while
     * claiming an observation was read.
     */
    public static function report(string $repo): array {
        self::assert_journal_prerequisite();

        // `true` follows wp duo capabilities' own read-only capability path:
        // compatibility evidence can be reported even where an ordinary
        // mutation command would later refuse. Pending's shared gate walk
        // retains its normal policy validation when it reads the same facts.
        $policy = Policy::load($repo, null, true);
        try {
            $journal = Journal::report_read_only($policy);
        } catch (CommandRefusalException $failure) {
            if ($failure->reasonCode === 'journal_evidence_unreadable') {
                self::refuse_journal_report_read_error($failure);
            }
            throw $failure;
        }
        try {
            $pending = Pending::scan($repo, $policy);
        } catch (CommandRefusalException $failure) {
            if ($failure->reasonCode === 'pending_evidence_unreadable') {
                self::refuse_pending_read_error($failure);
            }
            throw $failure;
        }
        $survey = AdapterSources::survey($repo);
        // Existing Policy vocabulary remains the capability/readiness source.
        // It negotiates provider identity/capability declarations, never the
        // provider `invoke()` channel; that distinction stays explicit below.
        $capabilities = $policy->capability_report(['operation' => 'promote']);

        return self::from_facts(
            self::site_policy_sha256($repo),
            $journal,
            $pending,
            $survey,
            $capabilities
        );
    }

    /**
     * Pure projection seam.  The offline regression supplies existing-path
     * shaped facts here, so the public schema/redaction/hash contract can be
     * proven without booting a second WordPress environment.
     *
     * @param array<string,mixed> $journal
     * @param list<array<string,mixed>> $pending
     * @param array<string,mixed> $survey
     * @param array<string,mixed> $capabilities
     */
    public static function from_facts(
        string $sitePolicySha256,
        array $journal,
        array $pending,
        array $survey,
        array $capabilities
    ): array {
        self::assert_hash($sitePolicySha256);

        $document = [
            'authority' => false,
            'catalog' => self::catalog_projection($survey),
            'deferred' => self::deferred(),
            'format' => self::FORMAT,
            'journal' => self::journal_projection($journal),
            'pending' => self::pending_projection($pending),
            'policy' => self::policy_projection($capabilities),
            'redaction' => self::REDACTION,
            'repository' => [
                // This binds only the bytes actually read here. It is not a
                // repository revision, working-tree identity, or code-state
                // claim, all of which would need distinct observed evidence.
                'site_policy_sha256' => $sitePolicySha256,
            ],
            'target' => [
                'agent_version' => self::agent_version(),
                'spec_version' => self::spec_version(),
            ],
        ];
        $document['observation_hash'] = self::hash_document($document);
        return $document;
    }

    /** Canonical hash basis shared conceptually with the host validator. */
    public static function hash_document(array $document): string {
        unset($document['observation_hash']);
        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    private static function site_policy_sha256(string $repo): string {
        $site = Canon::read_file(rtrim($repo, '/') . '/site.duo.json');
        return 'sha256:' . hash('sha256', $site);
    }

    private static function agent_version(): string {
        return defined('DUO_AGENT_VERSION') ? (string) DUO_AGENT_VERSION : 'unknown';
    }

    private static function spec_version(): int {
        return defined('DUO_SPEC_VERSION') ? (int) DUO_SPEC_VERSION : 0;
    }

    /**
     * The only prerequisite probe is a read.  No Ledger repair is permitted.
     *
     * The probe itself moved to Journal::table_state() when a second read-only
     * reader (EffectDeclarationCoverage) needed the identical SHOW TABLES /
     * last_error discipline; what stays here is this command's own refusal
     * contract, which is not shareable — `adapter_observation_prerequisite_absent`
     * and `adapter_observation_journal_unreadable` are its public reason codes.
     * `unusable` (no usable $wpdb) and `absent` (usable $wpdb, no table) keep
     * folding into the one prerequisite refusal this command has always raised.
     */
    private static function assert_journal_prerequisite(): void {
        global $wpdb;
        $state = Journal::table_state($wpdb);
        if ($state === 'unreadable') {
            self::refuse_journal_prerequisite_read_error();
        }
        if ($state !== 'present') {
            self::refuse_prerequisite();
        }
    }

    /** A failed prerequisite probe is not evidence that the table is absent. */
    private static function refuse_journal_prerequisite_read_error(): never {
        throw new CommandRefusalException(
            'adapter_observation_journal_unreadable',
            'adapter observation could not read the existing provenance journal',
            'inspect and repair the journal through the existing controlled workflow before collecting proposal evidence',
            [[
                'code' => 'adapter_observation_journal_unreadable',
                'message' => 'the observer will not treat a failed journal read as an absent journal',
                'remediation' => 'restore readable provenance state before collecting adapter observation evidence',
            ]],
            'duo: adapter observation refused because the provenance journal prerequisite probe failed'
        );
    }

    /** Translate the repository fact without leaking another command's contract. */
    private static function refuse_journal_report_read_error(\Throwable $previous): never {
        throw new CommandRefusalException(
            'adapter_observation_journal_unreadable',
            'adapter observation could not read the existing provenance journal',
            'inspect and repair the journal through the existing controlled workflow before collecting proposal evidence',
            [[
                'code' => 'adapter_observation_journal_unreadable',
                'message' => 'the observer will not treat a failed journal read as an empty journal',
                'remediation' => 'restore readable provenance state before collecting adapter observation evidence',
            ]],
            'duo: adapter observation refused because the provenance journal SELECT failed',
            $previous
        );
    }

    private static function refuse_prerequisite(): never {
        throw new CommandRefusalException(
            'adapter_observation_prerequisite_absent',
            'adapter observation requires an existing provenance journal table',
            'install or repair the agent through the existing controlled workflow, then rerun adapter observation',
            [[
                'code' => 'adapter_observation_prerequisite_absent',
                'message' => 'the observer will not create or repair provenance state',
                'remediation' => 'restore the existing journal prerequisite before collecting proposal evidence',
            ]],
            'duo: adapter observation refused because its provenance journal prerequisite is absent'
        );
    }

    private static function refuse_pending_read_error(\Throwable $previous): never {
        throw new CommandRefusalException(
            'adapter_observation_pending_unreadable',
            'adapter observation could not read the existing pending-review evidence',
            'inspect and repair the target database through the existing controlled workflow before collecting proposal evidence',
            [[
                'code' => 'adapter_observation_pending_unreadable',
                'message' => 'the observer will not treat a failed pending read as an empty review queue',
                'remediation' => 'restore readable target evidence before collecting adapter observation evidence',
            ]],
            'duo: adapter observation refused because a pending evidence SELECT failed',
            $previous
        );
    }

    /** @param array<string,mixed> $report */
    private static function journal_projection(array $report): array {
        if (!is_array($report['rows'] ?? null) || !array_is_list($report['rows'])) {
            self::invalid_fact();
        }
        $rows = [];
        $observations = 0;
        foreach ($report['rows'] as $row) {
            if (!is_array($row)) {
                self::invalid_fact();
            }
            $surface = self::enum($row['surface'] ?? null, self::JOURNAL_SURFACES);
            $caps = $row['caps'] ?? null;
            if (!is_string($caps)) {
                self::invalid_fact();
            }
            $capability = $caps === '' ? 'none' : self::enum($caps, self::JOURNAL_CAPABILITIES);
            $proposal = self::enum($row['proposal'] ?? null, self::JOURNAL_PROPOSALS);
            $verdict = self::enum($row['verdict'] ?? null, self::JOURNAL_VERDICTS);
            $count = self::count($row['n'] ?? null);
            $tableClass = match ((string) ($row['table'] ?? '')) {
                'options' => 'options',
                'postmeta' => 'post_meta',
                'termmeta' => 'term_meta',
                'usermeta' => 'user_meta',
                default => 'other',
            };
            // `item` and the journal's hook/actor/raw query facts are never
            // part of this key or row.  Grouping is intentionally one-way.
            $key = implode("\0", [$tableClass, $surface, $capability, $proposal, $verdict]);
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'capability_class' => $capability,
                    'count' => 0,
                    'proposal' => $proposal,
                    'surface' => $surface,
                    'table_class' => $tableClass,
                    'verdict' => $verdict,
                ];
            }
            $rows[$key]['count'] += $count;
            $observations += $count;
        }
        ksort($rows, SORT_STRING);

        return [
            'rows' => array_values($rows),
            'summary' => [
                'abstain' => self::count($report['abstain'] ?? null),
                'agree' => self::count($report['agree'] ?? null),
                'disagree' => self::count($report['disagree'] ?? null),
                'observations' => $observations,
                'unclassified' => self::count($report['unclassified'] ?? null),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private static function pending_projection(array $items): array {
        $out = [];
        $secretCount = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                self::invalid_fact();
            }
            $section = self::enum($item['section'] ?? null, self::PENDING_SECTIONS);
            if (!is_string($item['key'] ?? null) || $item['key'] === '') {
                self::invalid_fact();
            }
            $proposal = $item['proposal'] ?? null;
            if ($proposal !== null) {
                $proposal = self::enum($proposal, self::PENDING_PROPOSALS);
            }
            $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
            $journal = is_array($evidence['journal'] ?? null) ? $evidence['journal'] : [];
            $secret = self::secret_label($item['secret'] ?? null);
            $secretCount += $secret === null ? 0 : 1;
            $out[] = [
                'counts' => [
                    'entities' => array_key_exists('entities', $evidence) ? self::count($evidence['entities']) : 0,
                    'journal' => array_key_exists('n', $journal) ? self::count($journal['n']) : 0,
                ],
                'key' => self::structural_label($item['key']),
                'proposal' => $proposal,
                'reference' => self::reference_projection($item['ref_hint'] ?? null),
                'section' => $section,
                'secret' => $secret,
                'shapes' => self::shape_projection($evidence['value_shapes'] ?? []),
            ];
        }
        usort(
            $out,
            static fn(array $a, array $b): int => [
                $a['section'], $a['key']['encoding'], $a['key']['label'],
            ] <=> [
                $b['section'], $b['key']['encoding'], $b['key']['label'],
            ]
        );
        return [
            'items' => $out,
            'summary' => ['items' => count($out), 'secret' => $secretCount],
        ];
    }

    private static function reference_projection(mixed $hint): ?array {
        if ($hint === null) {
            return null;
        }
        if (!is_array($hint)) {
            self::invalid_fact();
        }
        $kind = self::enum($hint['kind'] ?? null, ['post', 'term']);
        if (!is_string($hint['post_type'] ?? null) || $hint['post_type'] === '') {
            self::invalid_fact();
        }
        // Deliberately do not touch `id` or `title`: they are target-local
        // resolution data, not adapter-authoring evidence.
        return ['kind' => $kind, 'type' => self::structural_label($hint['post_type'])];
    }

    private static function secret_label(mixed $secret): ?string {
        if ($secret === null) {
            return null;
        }
        if (!is_string($secret)) {
            self::invalid_fact();
        }
        $label = match ($secret) {
            'hard:aws key' => 'hard:aws_key',
            'hard:github token' => 'hard:github_token',
            'hard:jwt' => 'hard:jwt',
            'hard:private key' => 'hard:private_key',
            'hard:slack token' => 'hard:slack_token',
            'hard:stripe key' => 'hard:stripe_key',
            'suspicious' => 'suspicious',
            default => null,
        };
        if ($label !== null && in_array($label, self::PENDING_SECRETS, true)) {
            return $label;
        }
        self::invalid_fact();
    }

    /** @return list<string> */
    private static function shape_projection(mixed $shapes): array {
        if (!is_array($shapes) || !array_is_list($shapes)) {
            return [];
        }
        $out = [];
        foreach ($shapes as $shape) {
            if (!is_string($shape)) {
                $out['other'] = true;
                continue;
            }
            $normalized = match (strtolower($shape)) {
                'array' => 'array',
                'bool', 'boolean' => 'bool',
                'double', 'float' => 'float',
                'int', 'integer' => 'int',
                'null' => 'null',
                'object' => 'object',
                'string' => 'string',
                default => 'other',
            };
            $out[$normalized] = true;
        }
        $out = array_keys($out);
        sort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,mixed> $survey */
    private static function catalog_projection(array $survey): array {
        foreach (['adapters', 'not_installed', 'refusals', 'sources'] as $key) {
            if (!is_array($survey[$key] ?? null) || !array_is_list($survey[$key])) {
                self::invalid_fact();
            }
        }
        $sources = [];
        foreach ($survey['sources'] as $source) {
            if (!is_array($source)) {
                self::invalid_fact();
            }
            $name = self::enum($source['source'] ?? null, self::SOURCES);
            if (!is_bool($source['scanned'] ?? null) || isset($sources[$name])) {
                self::invalid_fact();
            }
            $sources[$name] = ['scanned' => $source['scanned'], 'source' => $name];
        }
        foreach (self::SOURCES as $source) {
            if (!isset($sources[$source])) {
                self::invalid_fact();
            }
        }
        ksort($sources, SORT_STRING);

        $installed = [];
        foreach ($survey['adapters'] as $row) {
            if (!is_array($row)) {
                self::invalid_fact();
            }
            $surfaces = is_array($row['executable_surfaces'] ?? null) ? $row['executable_surfaces'] : null;
            if ($surfaces === null || !is_array($surfaces['manifest_providers'] ?? null)
                || !is_array($surfaces['regenerators'] ?? null)) {
                self::invalid_fact();
            }
            $sha = $row['sha256'] ?? null;
            if (!is_string($sha) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
                self::invalid_fact();
            }
            $certification = $row['certification'] ?? null;
            if ($certification !== null) {
                $certification = self::enum($certification, self::CERTIFICATIONS);
            }
            $grammar = is_array($row['grammar'] ?? null) ? ($row['grammar']['status'] ?? null) : null;
            $installed[] = [
                'certification' => $certification,
                'grammar' => self::enum($grammar, self::GRAMMAR),
                'interpreter' => ($surfaces['interpreter'] ?? null) !== null,
                'manifest_provider_count' => count($surfaces['manifest_providers']),
                'name' => self::adapter_name($row['name'] ?? null),
                'regenerator_count' => count($surfaces['regenerators']),
                'sha256' => 'sha256:' . $sha,
                'source' => self::enum($row['source'] ?? null, self::SOURCES),
                'trust_tier' => self::enum($row['trust_tier'] ?? null, self::TRUST_TIERS),
            ];
        }
        usort($installed, static fn(array $a, array $b): int => [$a['name'], $a['source']] <=> [$b['name'], $b['source']]);

        $notInstalled = [];
        foreach ($survey['not_installed'] as $row) {
            if (!is_array($row)) {
                self::invalid_fact();
            }
            $name = $row['name'] ?? null;
            if ($name !== null) {
                // A refused plugin manifest can carry an arbitrary declared
                // name. Keep the source/refusal fact, but omit a name that is
                // not already the engine's canonical safe identity grammar.
                $name = self::optional_adapter_name($name);
            }
            $winner = $row['winner'] ?? null;
            if ($winner !== null && !is_array($winner)) {
                self::invalid_fact();
            }
            $notInstalled[] = [
                'name' => $name,
                'reason_code' => self::code($row['reason_code'] ?? null),
                'source' => self::enum($row['source'] ?? null, self::SOURCES),
                'winner_source' => $winner === null ? null : self::enum($winner['source'] ?? null, self::SOURCES),
            ];
        }
        usort($notInstalled, static fn(array $a, array $b): int => [$a['name'] ?? '', $a['reason_code']] <=> [$b['name'] ?? '', $b['reason_code']]);

        $refusals = [];
        foreach ($survey['refusals'] as $row) {
            if (!is_array($row) || !is_array($row['paths'] ?? null) || !array_is_list($row['paths'])) {
                self::invalid_fact();
            }
            $refusals[] = [
                'code' => self::code($row['code'] ?? null),
                'path_count' => count($row['paths']),
                // Three words since G2-FIXES C2: `library` is a row about the
                // agent's own manifest directory (an installed-but-inert typed
                // revocation document), which is neither one adapter nor one
                // source. Admitted here rather than redacted to `?`, because an
                // observation that hid the scope would report the row as
                // unclassifiable and invite a reader to guess.
                'scope' => self::enum($row['scope'] ?? null, [
                    AdapterSources::SCOPE_ADAPTER,
                    AdapterSources::SCOPE_LIBRARY,
                    AdapterSources::SCOPE_SOURCE,
                ]),
                'source' => self::enum($row['source'] ?? null, self::SOURCES),
            ];
        }
        usort($refusals, static fn(array $a, array $b): int => [$a['source'], $a['scope'], $a['code'], $a['path_count']] <=> [$b['source'], $b['scope'], $b['code'], $b['path_count']]);

        return [
            // This names the surveyed source wire vocabulary that supplied
            // these rows. It is deliberately not `duo-adapter-catalog/v2`:
            // this is a lossy, value-redacted AdapterSources projection, not
            // the host catalog's full offline report.
            'format' => AdapterSources::FORMAT,
            'installed' => $installed,
            'not_installed' => $notInstalled,
            'refusals' => $refusals,
            'sources' => array_values($sources),
            'summary' => [
                'installed' => count($installed),
                'not_installed' => count($notInstalled),
                'refusals' => count($refusals),
            ],
        ];
    }

    /** @param array<string,mixed> $report */
    private static function policy_projection(array $report): array {
        if (!is_bool($report['ready'] ?? null) || !is_array($report['manifests'] ?? null)
            || !array_is_list($report['manifests']) || !is_array($report['blockers'] ?? null)
            || !array_is_list($report['blockers'])) {
            self::invalid_fact();
        }
        $rows = [];
        foreach ($report['manifests'] as $row) {
            if (!is_array($row) || !is_array($row['source'] ?? null) || !is_array($row['verdict'] ?? null)
                || !is_array($row['operations'] ?? null) || !array_is_list($row['operations'])
                || !is_array($row['surfaces'] ?? null) || !array_is_list($row['surfaces'])) {
                self::invalid_fact();
            }
            $rows[] = [
                'adapter' => self::adapter_name($row['name'] ?? null),
                'claim_status' => self::enum($row['status'] ?? null, self::CLAIM_STATUSES),
                'operation_count' => count($row['operations']),
                'source' => self::enum($row['source']['source'] ?? null, array_merge(self::SOURCES, ['unknown'])),
                'surface_count' => count($row['surfaces']),
                'trust_tier' => self::enum($row['source']['trust_tier'] ?? null, array_merge(self::TRUST_TIERS, ['unknown'])),
                'verdict' => self::enum($row['verdict']['status'] ?? null, self::VERDICTS),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['adapter'] <=> $b['adapter']);

        $blockers = [];
        foreach ($report['blockers'] as $row) {
            if (!is_array($row)) {
                self::invalid_fact();
            }
            $code = $row['code'] ?? null;
            if ($code !== null) {
                $code = self::code($code);
            }
            $blockers[] = [
                'adapter' => self::adapter_name($row['name'] ?? null),
                'code' => $code,
                'source' => self::enum($row['source'] ?? AdapterSources::SHIPPED, array_merge(self::SOURCES, ['unknown'])),
                'status' => self::enum($row['status'] ?? null, self::BLOCKER_STATUSES),
                'trust_tier' => self::enum($row['trust_tier'] ?? 'unknown', array_merge(self::TRUST_TIERS, ['unknown'])),
            ];
        }
        usort($blockers, static fn(array $a, array $b): int => [$a['adapter'], $a['code'] ?? ''] <=> [$b['adapter'], $b['code'] ?? '']);

        $registry = $report['registry_sha256'] ?? null;
        if ($registry !== null && (!is_string($registry) || preg_match('/^[a-f0-9]{64}$/D', $registry) !== 1)) {
            self::invalid_fact();
        }
        return [
            'blockers' => $blockers,
            'capabilities' => $rows,
            'readiness' => $report['ready'] ? 'ready' : 'blocked',
            'registry_sha256' => $registry === null ? null : 'sha256:' . $registry,
            'summary' => ['blockers' => count($blockers), 'capabilities' => count($rows)],
        ];
    }

    /** @return list<array{status:string,subject:string,statement:string}> */
    private static function deferred(): array {
        return [
            [
                'status' => 'deferred',
                'statement' => 'this report is proposal evidence and has no authority',
                'subject' => 'proposal_evidence',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove table semantics',
                'subject' => 'table_semantics',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove apply',
                'subject' => 'apply',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove provider invocation',
                'subject' => 'provider_invocation',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove rollback',
                'subject' => 'rollback',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove plugin/theme upgrade, downgrade, or removal',
                'subject' => 'version_lifecycle',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove publication',
                'subject' => 'publication',
            ],
            [
                'status' => 'deferred',
                'statement' => 'this report does not prove certification',
                'subject' => 'certification',
            ],
            [
                'status' => 'deferred',
                'statement' => 'normal plugin/provider registration and capability negotiation remain enabled; third-party callbacks may have side effects before or during evidence collection; Duo invokes no provider action and performs no explicit mutation after observer entry',
                'subject' => 'bootstrap_effects',
            ],
        ];
    }

    /**
     * @return array{encoding:'literal'|'sha256',label:string}
     */
    private static function structural_label(string $value): array {
        if (strlen($value) <= 191
            && preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) === 1
            && !CommandRefusalException::containsSensitivePublicDetail($value)) {
            return ['encoding' => 'literal', 'label' => $value];
        }
        // A literal key can itself happen to begin `sha256:`. Keep a closed
        // witness so consumers never mistake that literal for a redaction.
        return ['encoding' => 'sha256', 'label' => 'sha256:' . hash('sha256', $value)];
    }

    private static function adapter_name(mixed $value): string {
        if (!is_string($value) || CommandRefusalException::containsSensitivePublicDetail($value)) {
            self::invalid_fact();
        }
        try {
            // Keep exactly the engine's canonical identity grammar: valid
            // names may start with a digit and carry dots/underscores.
            AdapterSources::assert_name($value, 'adapter observation projection');
        } catch (\Throwable) {
            self::invalid_fact();
        }
        return $value;
    }

    private static function optional_adapter_name(mixed $value): ?string {
        if (!is_string($value) || CommandRefusalException::containsSensitivePublicDetail($value)) {
            return null;
        }
        try {
            AdapterSources::assert_name($value, 'adapter observation projection');
            return $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function code(mixed $value): string {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $value) !== 1) {
            self::invalid_fact();
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): string {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::invalid_fact();
        }
        return $value;
    }

    private static function count(mixed $value): int {
        if (!is_int($value) || $value < 0) {
            self::invalid_fact();
        }
        return $value;
    }

    private static function assert_hash(string $value): void {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            self::invalid_fact();
        }
    }

    private static function invalid_fact(): never {
        throw new CommandRefusalException(
            'adapter_observation_projection_failed',
            'adapter observation encountered an unsupported target fact',
            'inspect private target evidence and update the observer only with an explicit schema review',
            [[
                'code' => 'adapter_observation_projection_failed',
                'message' => 'an existing observation source did not match the closed redacted projection',
                'remediation' => 'preserve the target state and inspect private evidence before another observation',
            ]],
            'duo: adapter observation refused because an input fact cannot enter its closed redacted projection'
        );
    }
}
