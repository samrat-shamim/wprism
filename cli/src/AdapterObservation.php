<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Canon;

/**
 * Host boundary for the target-owned, redacted adapter observation document.
 *
 * The target owns all facts. The host owns exactly three things: selecting a
 * configured environment/repo_path through EnvironmentDriver, requiring the
 * target's one canonical JSON response, and optionally retaining that already
 * validated response as a create-only local evidence file. It never accepts a
 * local --repo path, and it never treats the evidence as adapter authority.
 */
final class AdapterObservation {
    public const FORMAT = 'duo-adapter-observation/v1';
    private const CATALOG_FORMAT = 'duo-adapter-sources/v2';
    private const SOURCES = ['plugin', 'shipped', 'site'];
    private const TRUST_TIERS = [
        'compatibility_shim', 'declarative_manifest', 'native_action', 'plugin_provider', 'unknown',
    ];
    private const JOURNAL_SURFACES = ['admin', 'ajax', 'cli', 'cron', 'front', 'rest'];
    private const JOURNAL_CAPABILITIES = ['none', 'edit_posts', 'manage_options'];
    private const JOURNAL_PROPOSALS = ['authored', 'review', 'runtime'];
    private const JOURNAL_VERDICTS = ['abstain', 'agree', 'disagree', 'managed', 'unclassified'];
    private const PENDING_SECTIONS = ['options', 'post_meta', 'scope', 'table_meta', 'term_meta', 'user_meta', 'widgets'];
    private const PENDING_PROPOSALS = ['authored', 'runtime'];
    private const PENDING_SECRETS = [
        'hard:aws_key', 'hard:github_token', 'hard:jwt', 'hard:private_key', 'hard:slack_token',
        'hard:stripe_key', 'suspicious',
    ];
    private const PENDING_SHAPES = ['array', 'bool', 'float', 'int', 'null', 'object', 'other', 'string'];
    private const GRAMMAR = ['blocked_by_source_refusal', 'error', 'ok'];
    private const CERTIFICATIONS = [
        'certification_unjudged', 'registry', 'signed_unpinned', 'third_party_signed', 'uncertified',
    ];
    private const CLAIM_STATUSES = ['certified', 'excluded', 'experimental', 'uncertified', 'unsupported'];
    private const VERDICTS = ['blocked', 'certified'];
    private const BLOCKER_STATUSES = ['blocked', 'unreviewed', 'unsupported'];

    /**
     * Execute one target command after all local-only validation. A successful
     * invocation always sends exactly this one target request; no transport
     * fallback, retry, or host-local repository path is available.
     *
     * @param list<string> $args flags after the environment name
     */
    public static function run(EnvironmentDriver $driver, array $args): int {
        $jsonRequested = self::json_requested($args);
        try {
            $options = self::parse_options($args);
            self::assert_output_destination($options['out']);
        } catch (\Throwable) {
            return self::refusal(
                $jsonRequested,
                'invalid_arguments',
                'adapter-observe accepts only one optional --out=<local-file> or --format=json',
                'supply a trusted environment and one supported output option, then retry'
            );
        }

        try {
            $result = $driver->captureWp([
                'duo',
                'adapter-observe',
                '--repo=' . $driver->repoPath(),
                '--format=json',
            ]);
        } catch (\Throwable) {
            return self::refusal(
                $options['json'],
                'adapter_observation_target_failed',
                'the target did not return a completed adapter observation',
                'inspect private target and transport evidence, then retry'
            );
        }

        if (!is_array($result) || !is_int($result['exit'] ?? null) || $result['exit'] !== 0
            || !is_string($result['stdout'] ?? null)) {
            return self::refusal(
                $options['json'],
                'adapter_observation_target_failed',
                'the target did not return a completed adapter observation',
                'inspect private target and transport evidence, then retry'
            );
        }

        try {
            $document = self::validate((string) $result['stdout']);
            $canonical = Canon::encode($document);
            if ($options['out'] !== null) {
                self::write_create_only($options['out'], $canonical);
            }
        } catch (\Throwable) {
            return self::refusal(
                $options['json'],
                'adapter_observation_invalid',
                'the target response did not satisfy the closed adapter observation contract',
                'inspect private target evidence and restore the expected observer contract before retrying'
            );
        }

        if ($options['json']) {
            echo $canonical;
            return 0;
        }
        self::render_human($document, $options['out'] !== null);
        return 0;
    }

    /**
     * Decode, schema-check, hash-check, ordering-check, and byte-check one
     * target response. Public for offline contract tests; callers receive no
     * Throwable text from run(), so malformed target/plugin bytes never cross
     * the host boundary.
     *
     * @return array<string,mixed>
     */
    public static function validate(string $wire): array {
        if ($wire === '' || strlen($wire) > 4 * 1024 * 1024 || !str_ends_with($wire, "\n")) {
            throw new \RuntimeException('invalid observation envelope');
        }
        try {
            $document = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('invalid observation JSON', 0, $e);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('invalid observation document');
        }

        self::validate_document($document);
        $expectedHash = self::hash_document($document);
        if (!is_string($document['observation_hash']) || !hash_equals($expectedHash, $document['observation_hash'])) {
            throw new \RuntimeException('invalid observation hash');
        }

        // Canonical bytes are part of the transport contract. This catches
        // reordered objects/lists even when a caller recomputes a matching
        // hash over its own reordered JSON value.
        if (!hash_equals(Canon::encode($document), $wire)) {
            throw new \RuntimeException('noncanonical observation bytes');
        }
        return $document;
    }

    /** @param array<string,mixed> $document */
    public static function hash_document(array $document): string {
        unset($document['observation_hash']);
        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /** @return array{json:bool,out:?string} */
    private static function parse_options(array $args): array {
        $json = false;
        $out = null;
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new \RuntimeException('invalid argument');
            }
            if ($arg === '--format=json') {
                if ($json) throw new \RuntimeException('duplicate format');
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--out=')) {
                if ($out !== null) throw new \RuntimeException('duplicate output');
                $out = substr($arg, strlen('--out='));
                if ($out === '' || str_contains($out, "\0")) {
                    throw new \RuntimeException('invalid output');
                }
                continue;
            }
            throw new \RuntimeException('unsupported argument');
        }
        if ($json && $out !== null) {
            throw new \RuntimeException('mutually exclusive output');
        }
        return ['json' => $json, 'out' => $out];
    }

    private static function json_requested(array $args): bool {
        return in_array('--format=json', $args, true);
    }

    private static function assert_output_destination(?string $out): void {
        if ($out === null) return;
        // lstat() deliberately sees a dangling symlink and a FIFO. Every
        // pre-existing directory entry is refused, not merely regular files.
        if (@lstat($out) !== false) {
            throw new \RuntimeException('output exists');
        }
        $dir = dirname($out);
        $stat = @lstat($dir);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0040000) || is_link($dir)) {
            throw new \RuntimeException('unsafe output directory');
        }
    }

    private static function write_create_only(string $out, string $canonical): void {
        // Repeat the cheap preflight immediately before the link(2) commit;
        // link itself is the no-clobber atomic primitive for a same-directory
        // temporary file, so a race can only become a refusal.
        self::assert_output_destination($out);
        $temporary = @tempnam(dirname($out), '.duo-adapter-observation-');
        if (!is_string($temporary) || $temporary === '') {
            throw new \RuntimeException('temporary output failed');
        }
        $linked = false;
        try {
            if (@file_put_contents($temporary, $canonical, LOCK_EX) === false) {
                throw new \RuntimeException('output write failed');
            }
            if (!@link($temporary, $out)) {
                throw new \RuntimeException('output destination changed');
            }
            $linked = true;
        } finally {
            // unlinking the temporary link after an atomic link() commit
            // leaves the destination intact; failures leave no partial file.
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
        if (!$linked) {
            throw new \RuntimeException('output link failed');
        }
    }

    /** @param array<string,mixed> $document */
    private static function validate_document(array $document): void {
        self::object($document, [
            'authority', 'catalog', 'deferred', 'format', 'journal', 'observation_hash', 'pending', 'policy',
            'redaction', 'repository', 'target',
        ]);
        if ($document['authority'] !== false || $document['format'] !== self::FORMAT
            || $document['redaction'] !== 'values_omitted') {
            throw new \RuntimeException('invalid observation constants');
        }
        self::sha256($document['observation_hash']);

        self::object($document['repository'], ['site_policy_sha256']);
        self::sha256($document['repository']['site_policy_sha256']);

        self::object($document['target'], ['agent_version', 'spec_version']);
        self::agent_version($document['target']['agent_version']);
        self::count($document['target']['spec_version']);

        self::validate_journal($document['journal']);
        self::validate_pending($document['pending']);
        self::validate_catalog($document['catalog']);
        self::validate_policy($document['policy']);
        self::validate_deferred($document['deferred']);
    }

    private static function validate_journal(mixed $journal): void {
        self::object($journal, ['rows', 'summary']);
        self::list($journal['rows']);
        $last = null;
        $observations = 0;
        foreach ($journal['rows'] as $row) {
            self::object($row, ['capability_class', 'count', 'proposal', 'surface', 'table_class', 'verdict']);
            self::enum($row['capability_class'], self::JOURNAL_CAPABILITIES);
            self::count($row['count']);
            self::enum($row['proposal'], self::JOURNAL_PROPOSALS);
            self::enum($row['surface'], self::JOURNAL_SURFACES);
            self::enum($row['table_class'], ['options', 'post_meta', 'term_meta', 'user_meta', 'other']);
            self::enum($row['verdict'], self::JOURNAL_VERDICTS);
            $key = implode("\0", [
                $row['table_class'], $row['surface'], $row['capability_class'], $row['proposal'], $row['verdict'],
            ]);
            self::ordered($last, $key);
            $last = $key;
            $observations += $row['count'];
        }
        self::object($journal['summary'], ['abstain', 'agree', 'disagree', 'observations', 'unclassified']);
        foreach ($journal['summary'] as $count) self::count($count);
        if ($journal['summary']['observations'] !== $observations
            || $journal['summary']['abstain'] + $journal['summary']['agree']
                + $journal['summary']['disagree'] + $journal['summary']['unclassified'] > $observations) {
            throw new \RuntimeException('invalid journal summary');
        }
    }

    private static function validate_pending(mixed $pending): void {
        self::object($pending, ['items', 'summary']);
        self::list($pending['items']);
        $last = null;
        $secrets = 0;
        foreach ($pending['items'] as $item) {
            self::object($item, ['counts', 'key', 'proposal', 'reference', 'section', 'secret', 'shapes']);
            self::object($item['counts'], ['entities', 'journal']);
            self::count($item['counts']['entities']);
            self::count($item['counts']['journal']);
            self::structural_label($item['key']);
            if ($item['proposal'] !== null) self::enum($item['proposal'], self::PENDING_PROPOSALS);
            self::reference($item['reference']);
            self::enum($item['section'], self::PENDING_SECTIONS);
            if ($item['secret'] !== null) {
                self::enum($item['secret'], self::PENDING_SECRETS);
                $secrets++;
            }
            self::list($item['shapes']);
            self::unique_sorted_enums($item['shapes'], self::PENDING_SHAPES);
            $key = $item['section'] . "\0" . $item['key']['encoding'] . "\0" . $item['key']['label'];
            self::ordered($last, $key);
            $last = $key;
        }
        self::object($pending['summary'], ['items', 'secret']);
        self::count($pending['summary']['items']);
        self::count($pending['summary']['secret']);
        if ($pending['summary']['items'] !== count($pending['items']) || $pending['summary']['secret'] !== $secrets) {
            throw new \RuntimeException('invalid pending summary');
        }
    }

    private static function validate_catalog(mixed $catalog): void {
        self::object($catalog, ['format', 'installed', 'not_installed', 'refusals', 'sources', 'summary']);
        if ($catalog['format'] !== self::CATALOG_FORMAT) {
            throw new \RuntimeException('invalid source projection format');
        }
        self::list($catalog['sources']);
        if (count($catalog['sources']) !== count(self::SOURCES)) throw new \RuntimeException('invalid sources');
        $sourceNames = [];
        foreach ($catalog['sources'] as $row) {
            self::object($row, ['scanned', 'source']);
            if (!is_bool($row['scanned'])) throw new \RuntimeException('invalid source scan state');
            self::enum($row['source'], self::SOURCES);
            $sourceNames[] = $row['source'];
        }
        if ($sourceNames !== self::SOURCES) throw new \RuntimeException('invalid source order');

        self::list($catalog['installed']);
        $last = null;
        foreach ($catalog['installed'] as $row) {
            self::object($row, [
                'certification', 'grammar', 'interpreter', 'manifest_provider_count', 'name', 'regenerator_count',
                'sha256', 'source', 'trust_tier',
            ]);
            if ($row['certification'] !== null) self::enum($row['certification'], self::CERTIFICATIONS);
            self::enum($row['grammar'], self::GRAMMAR);
            if (!is_bool($row['interpreter'])) throw new \RuntimeException('invalid interpreter flag');
            self::count($row['manifest_provider_count']);
            self::adapter_name($row['name']);
            self::count($row['regenerator_count']);
            self::sha256($row['sha256']);
            self::enum($row['source'], self::SOURCES);
            self::enum($row['trust_tier'], array_values(array_diff(self::TRUST_TIERS, ['unknown'])));
            $key = $row['name'] . "\0" . $row['source'];
            self::ordered($last, $key);
            $last = $key;
        }

        self::list($catalog['not_installed']);
        $last = null;
        foreach ($catalog['not_installed'] as $row) {
            self::object($row, ['name', 'reason_code', 'source', 'winner_source']);
            if ($row['name'] !== null) self::adapter_name($row['name']);
            self::code($row['reason_code']);
            self::enum($row['source'], self::SOURCES);
            if ($row['winner_source'] !== null) self::enum($row['winner_source'], self::SOURCES);
            $key = ($row['name'] ?? '') . "\0" . $row['reason_code'];
            self::ordered($last, $key);
            $last = $key;
        }

        self::list($catalog['refusals']);
        $last = null;
        foreach ($catalog['refusals'] as $row) {
            self::object($row, ['code', 'path_count', 'scope', 'source']);
            self::code($row['code']);
            self::count($row['path_count']);
            self::enum($row['scope'], ['adapter', 'source']);
            self::enum($row['source'], self::SOURCES);
            $key = implode("\0", [$row['source'], $row['scope'], $row['code'], (string) $row['path_count']]);
            self::ordered($last, $key);
            $last = $key;
        }

        self::object($catalog['summary'], ['installed', 'not_installed', 'refusals']);
        foreach ($catalog['summary'] as $count) self::count($count);
        if ($catalog['summary']['installed'] !== count($catalog['installed'])
            || $catalog['summary']['not_installed'] !== count($catalog['not_installed'])
            || $catalog['summary']['refusals'] !== count($catalog['refusals'])) {
            throw new \RuntimeException('invalid catalog summary');
        }
    }

    private static function validate_policy(mixed $policy): void {
        self::object($policy, ['blockers', 'capabilities', 'readiness', 'registry_sha256', 'summary']);
        self::enum($policy['readiness'], ['blocked', 'ready']);
        if ($policy['registry_sha256'] !== null) self::sha256($policy['registry_sha256']);

        self::list($policy['capabilities']);
        $last = null;
        foreach ($policy['capabilities'] as $row) {
            self::object($row, [
                'adapter', 'claim_status', 'operation_count', 'source', 'surface_count', 'trust_tier', 'verdict',
            ]);
            self::adapter_name($row['adapter']);
            self::enum($row['claim_status'], self::CLAIM_STATUSES);
            self::count($row['operation_count']);
            self::enum($row['source'], array_merge(self::SOURCES, ['unknown']));
            self::count($row['surface_count']);
            self::enum($row['trust_tier'], self::TRUST_TIERS);
            self::enum($row['verdict'], self::VERDICTS);
            self::ordered($last, $row['adapter']);
            $last = $row['adapter'];
        }

        self::list($policy['blockers']);
        $last = null;
        foreach ($policy['blockers'] as $row) {
            self::object($row, ['adapter', 'code', 'source', 'status', 'trust_tier']);
            self::adapter_name($row['adapter']);
            if ($row['code'] !== null) self::code($row['code']);
            self::enum($row['source'], array_merge(self::SOURCES, ['unknown']));
            self::enum($row['status'], self::BLOCKER_STATUSES);
            self::enum($row['trust_tier'], self::TRUST_TIERS);
            $key = $row['adapter'] . "\0" . ($row['code'] ?? '');
            self::ordered($last, $key);
            $last = $key;
        }

        self::object($policy['summary'], ['blockers', 'capabilities']);
        self::count($policy['summary']['blockers']);
        self::count($policy['summary']['capabilities']);
        if ($policy['summary']['blockers'] !== count($policy['blockers'])
            || $policy['summary']['capabilities'] !== count($policy['capabilities'])
            || ($policy['readiness'] === 'ready') !== ($policy['blockers'] === [])) {
            throw new \RuntimeException('invalid policy summary');
        }
    }

    private static function validate_deferred(mixed $deferred): void {
        self::list($deferred);
        // JSON object member order is already checked at the enclosing wire
        // boundary. Compare the closed values canonically here so decoding the
        // target's alphabetized object keys does not manufacture a PHP array
        // insertion-order difference.
        if (Canon::encode($deferred) !== Canon::encode(self::deferred())) {
            throw new \RuntimeException('invalid deferred boundary');
        }
    }

    /** @return list<array{status:string,statement:string,subject:string}> */
    private static function deferred(): array {
        return [
            ['status' => 'deferred', 'statement' => 'this report is proposal evidence and has no authority', 'subject' => 'proposal_evidence'],
            ['status' => 'deferred', 'statement' => 'this report does not prove table semantics', 'subject' => 'table_semantics'],
            ['status' => 'deferred', 'statement' => 'this report does not prove apply', 'subject' => 'apply'],
            ['status' => 'deferred', 'statement' => 'this report does not prove provider invocation', 'subject' => 'provider_invocation'],
            ['status' => 'deferred', 'statement' => 'this report does not prove rollback', 'subject' => 'rollback'],
            ['status' => 'deferred', 'statement' => 'this report does not prove plugin/theme upgrade, downgrade, or removal', 'subject' => 'version_lifecycle'],
            ['status' => 'deferred', 'statement' => 'this report does not prove publication', 'subject' => 'publication'],
            ['status' => 'deferred', 'statement' => 'this report does not prove certification', 'subject' => 'certification'],
            [
                'status' => 'deferred',
                'statement' => 'normal plugin/provider registration and capability negotiation remain enabled; third-party callbacks may have side effects before or during evidence collection; Duo invokes no provider action and performs no explicit mutation after observer entry',
                'subject' => 'bootstrap_effects',
            ],
        ];
    }

    private static function object(mixed $value, array $keys): void {
        if (!is_array($value) || array_is_list($value)) throw new \RuntimeException('expected object');
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) throw new \RuntimeException('unexpected object keys');
    }

    private static function list(mixed $value): void {
        if (!is_array($value) || !array_is_list($value)) throw new \RuntimeException('expected list');
    }

    private static function enum(mixed $value, array $allowed): void {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new \RuntimeException('invalid enum');
    }

    private static function count(mixed $value): void {
        if (!is_int($value) || $value < 0) throw new \RuntimeException('invalid count');
    }

    private static function sha256(mixed $value): void {
        if (!is_string($value) || preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException('invalid hash');
        }
    }

    private static function agent_version(mixed $value): void {
        if (!is_string($value) || ($value !== 'unknown'
            && preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/D', $value) !== 1)) {
            throw new \RuntimeException('invalid agent version');
        }
    }

    private static function adapter_name(mixed $value): void {
        if (!is_string($value)
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $value) !== 1
            || preg_match('/[a-z]/D', $value) !== 1
            || self::sensitive_literal($value)) {
            throw new \RuntimeException('invalid adapter name');
        }
    }

    private static function code(mixed $value): void {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $value) !== 1) {
            throw new \RuntimeException('invalid code');
        }
    }

    private static function structural_label(mixed $value): void {
        self::object($value, ['encoding', 'label']);
        self::enum($value['encoding'], ['literal', 'sha256']);
        if (!is_string($value['label'])) throw new \RuntimeException('invalid structural label');
        if ($value['encoding'] === 'literal') {
            if (strlen($value['label']) < 1 || strlen($value['label']) > 191
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value['label']) !== 1) {
                throw new \RuntimeException('invalid literal label');
            }
            if (self::sensitive_literal($value['label'])) {
                throw new \RuntimeException('sensitive literal label');
            }
            return;
        }
        self::sha256($value['label']);
    }

    private static function reference(mixed $value): void {
        if ($value === null) return;
        self::object($value, ['kind', 'type']);
        self::enum($value['kind'], ['post', 'term']);
        self::structural_label($value['type']);
    }

    private static function unique_sorted_enums(array $values, array $allowed): void {
        $last = null;
        foreach ($values as $value) {
            self::enum($value, $allowed);
            if ($last !== null && strcmp($last, $value) >= 0) {
                throw new \RuntimeException('unordered enum list');
            }
            $last = $value;
        }
    }

    /** Reject a list reordered away from the target's closed sort order. */
    private static function ordered(?string $last, string $current): void {
        if ($last !== null && strcmp($last, $current) > 0) {
            throw new \RuntimeException('unordered observation rows');
        }
    }

    /** A source may never smuggle a credential-looking literal through a safe grammar field. */
    private static function sensitive_literal(string $value): bool {
        return \Duo\Secrets::hard_match($value) !== null
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('~/(?:Users|home)/~i', str_replace('\\', '/', $value)) === 1;
    }

    private static function render_human(array $document, bool $wrote): void {
        echo "adapter observation\n";
        echo 'format: ' . self::FORMAT . "\n";
        echo "authority: false; redaction: values_omitted\n";
        echo 'journal observations: ' . $document['journal']['summary']['observations'] . "\n";
        echo 'pending structural items: ' . $document['pending']['summary']['items'] . "\n";
        echo 'policy readiness: ' . $document['policy']['readiness'] . "\n";
        echo 'observation hash: ' . $document['observation_hash'] . "\n";
        if ($wrote) echo "local evidence created atomically (path omitted)\n";
        echo "deferred: proposal evidence only; no table/apply/provider/rollback/upgrade/publication/certification proof\n";
    }

    private static function refusal(bool $json, string $reason, string $message, string $remediation): int {
        if ($json) {
            $payload = [
                'format' => 'duo-command-refusal/v1',
                'ok' => false,
                'command' => 'adapter-observe',
                'error' => $reason,
                'reason_code' => $reason,
                'message' => $message,
                'remediation' => $remediation,
                'details_redacted' => true,
            ];
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 1;
        }
        fwrite(STDERR, 'duo: adapter-observe: ' . $message . '; ' . $remediation . "\n");
        return 1;
    }
}
