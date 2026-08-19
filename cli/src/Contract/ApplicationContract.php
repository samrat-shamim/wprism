<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/ProjectionVocabulary.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * Parse, validate and digest the `duo-application-contract/v2` document
 * (round-3 MUP §3.2).
 *
 * v2 narrows `evidence_pins` to the two facts that still exist: the content
 * address of the reviewed dispositions a verdict was read from, and this
 * checkout's own copy of that document. v1 additionally pinned a per-subject
 * `bundles[]` and two generator-input hashes belonging to a generated
 * capability registry and its evidence record; both documents are gone, so a
 * v1 contract pins numbers nothing can observe or invalidate. Re-issuing every
 * accepted contract is the deliberate cost — validate() below names it — and
 * is preferable to accepting a document whose pins cannot be re-checked.
 *
 * The contract is the one place a human's reviewed declarations about a site
 * live. Everything downstream cites it: `duo release` puts `contract_digest`
 * in the frozen authorization plan, `duo verify` reads `journeys[]`, `duo
 * recover` reads `external_effects[]` for its does-not-restore list. That is
 * why validation here is a **closed key set per object** rather than a
 * required-keys check: an unrecognised key in a document that authorizes
 * production is either a typo whose declaration silently does nothing, or a
 * newer schema this build cannot honour. Both must refuse, and both refuse
 * with the key path named.
 *
 * Two rules are not schema, they are the round's honesty properties:
 *
 *  1. `attestation.state` is a closed enum `unsigned | signed`, and this
 *     profile only ever *writes* `unsigned` — the certification gate is
 *     deferred (MUP §7). The enum still admits `signed` so a future signed
 *     document parses rather than being unreadable; refusing to write one is
 *     ContractStore's job, at the boundary where writing happens.
 *  2. An `external_effects[]` entry may not be `decided_by: unresolved`.
 *     MUP §1.6's consequence is that an undeclared live lifecycle window
 *     blocks a release, and the *fix* is a reviewed declaration. A generator
 *     can propose the entry (ContractProposal does), but a proposal that was
 *     never reviewed must not become authority by being accepted unread.
 *
 * `contract_digest` is `sha256:` + the SHA-256 of the Canon-canonical
 * encoding of everything above it, i.e. the document minus that one key.
 * Canon sorts keys at every level, so the digest is independent of the order
 * a caller happened to build the array in — which is what makes "identical
 * inputs, identical bytes" testable at all.
 */
final class ApplicationContract {
    public const FORMAT = 'duo-application-contract/v2';

    /**
     * The generation this build supersedes. Named so the refusal can say WHY a
     * document that parsed yesterday does not parse now, rather than making an
     * operator diff two schemas to find out.
     */
    public const PRIOR_FORMAT = 'duo-application-contract/v1';

    public const ATTESTATION_FORMAT = 'duo-contract-attestation/v1';

    /** MUP §3.2: closed enum; this profile only ever writes `unsigned`. */
    public const ATTESTATION_STATES = ['unsigned', 'signed'];

    public const MANIFEST_SOURCES = ['shipped', 'site', 'plugin'];
    public const SITE_MODES = ['single-site', 'multisite'];
    public const DECIDED_BY = ['operator', 'platform-default', 'unresolved'];

    /** The reviewed reason MUP §1.6 requires before a live effect may be declared. */
    public const UNREVIEWED_DECIDED_BY = 'unresolved';

    private const TOP_LEVEL_REQUIRED = [
        'format', 'site', 'declarations', 'evidence_pins',
        'attestation', 'environment_bindings', 'contract_digest',
    ];

    private const DECLARATIONS_REQUIRED = [
        'stack', 'manifest_pins', 'surfaces', 'external_effects', 'journeys', 'unsupported',
    ];

    /**
     * MUP §2.1/§2.7's optional id -> operator-facing label map. It is what
     * lets `duo status` print "products / Ceramic Mug / price" and what lets
     * the surface catalog stay free of any plugin name: the label is data in
     * a reviewed document, never a branch in engine code.
     */
    private const DECLARATIONS_OPTIONAL = ['surface_labels'];

    /**
     * Parse a canonical JSON document (or an already-decoded array).
     *
     * @param bool $requireReviewedExternalEffects false only for the
     *        generated proposal, whose whole purpose is to carry the
     *        not-yet-reviewed placeholder a human is about to edit.
     */
    public static function parse(string|array $document, bool $requireReviewedExternalEffects = true): array {
        if (is_string($document)) {
            try {
                $decoded = Canon::decode($document);
            } catch (\RuntimeException $e) {
                throw new CommandRefusalException(
                    'contract_format_invalid',
                    'the application contract is not valid JSON',
                    'restore .duo/contract/contract.json from git, or regenerate it with duo contract propose',
                    [],
                    'contract JSON decode failed: ' . $e->getMessage(),
                    $e
                );
            }
            if (!is_array($decoded)) {
                throw self::refuseFormat('the application contract is not a JSON object');
            }
            $document = $decoded;
        }
        self::validate($document, $requireReviewedExternalEffects);

        return $document;
    }

    /** Validate shape, closed key sets, vocabulary and digest. */
    public static function validate(array $document, bool $requireReviewedExternalEffects = true): void {
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw self::refuseFormat(($document['format'] ?? null) === self::PRIOR_FORMAT
                ? 'this is a ' . self::PRIOR_FORMAT . ' contract: its evidence_pins bind a generated capability '
                    . 'registry and per-subject certification bundles that no longer exist, so it must be '
                    . 're-proposed and re-accepted as ' . self::FORMAT
                : 'the document is not a ' . self::FORMAT . ' contract');
        }
        self::closedKeys($document, self::TOP_LEVEL_REQUIRED, [], 'contract');

        self::validateSite(self::object($document, 'site', 'contract'));
        self::validateDeclarations(
            self::object($document, 'declarations', 'contract'),
            $requireReviewedExternalEffects
        );
        self::validateEvidencePins(self::object($document, 'evidence_pins', 'contract'));
        self::validateAttestation(self::object($document, 'attestation', 'contract'));
        self::validateEnvironmentBindings(self::object($document, 'environment_bindings', 'contract'));

        $digest = $document['contract_digest'];
        if (!is_string($digest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw self::refuseShape('contract.contract_digest is not a sha256: digest');
        }
        self::verifyDigest($document);
    }

    /**
     * The digest of everything above `contract_digest`.
     *
     * @param array<string,mixed> $document
     */
    public static function digest(array $document): string {
        unset($document['contract_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /**
     * Return the document with a freshly computed `contract_digest`.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public static function withDigest(array $document): array {
        $document['contract_digest'] = self::digest($document);

        return $document;
    }

    /** @param array<string,mixed> $document */
    public static function verifyDigest(array $document): void {
        $stated = (string) ($document['contract_digest'] ?? '');
        $computed = self::digest($document);
        if (!hash_equals($computed, $stated)) {
            throw new CommandRefusalException(
                'contract_digest_mismatch',
                'the application contract digest does not match its own content',
                'run duo contract propose and accept the reviewed proposal instead of editing contract.json by hand',
                [['stated' => $stated, 'computed' => $computed]]
            );
        }
    }

    /** Canonical bytes for the contract, exactly as they land on disk. */
    public static function encode(array $document): string {
        return Canon::encode($document);
    }

    /** @param array<string,mixed> $site */
    private static function validateSite(array $site): void {
        self::closedKeys($site, ['name', 'spec_version'], [], 'contract.site');
        self::nonEmptyString($site['name'], 'contract.site.name');
        if (!is_int($site['spec_version'])) {
            throw self::refuseShape('contract.site.spec_version must be an integer');
        }
    }

    /** @param array<string,mixed> $declarations */
    private static function validateDeclarations(
        array $declarations,
        bool $requireReviewedExternalEffects
    ): void {
        self::closedKeys(
            $declarations,
            self::DECLARATIONS_REQUIRED,
            self::DECLARATIONS_OPTIONAL,
            'contract.declarations'
        );
        self::validateStack(self::object($declarations, 'stack', 'contract.declarations'));

        foreach (self::rows($declarations, 'manifest_pins', 'contract.declarations') as $index => $pin) {
            $path = "contract.declarations.manifest_pins[$index]";
            self::closedKeys($pin, ['name', 'source', 'adapter_digest'], [], $path);
            self::nonEmptyString($pin['name'], "$path.name");
            self::enum($pin['source'], self::MANIFEST_SOURCES, "$path.source");
            self::nonEmptyString($pin['adapter_digest'], "$path.adapter_digest");
        }

        foreach (self::rows($declarations, 'surfaces', 'contract.declarations') as $index => $surface) {
            $path = "contract.declarations.surfaces[$index]";
            self::closedKeys(
                $surface,
                ['id', 'label', 'state_class', 'handling', 'operations', 'decided_by'],
                ['identity', 'decided_at', 'next_action'],
                $path
            );
            self::nonEmptyString($surface['id'], "$path.id");
            self::nonEmptyString($surface['label'], "$path.label");
            self::enum($surface['state_class'], ProjectionVocabulary::STATE_CLASSES, "$path.state_class");
            self::enum($surface['handling'], ProjectionVocabulary::HANDLINGS, "$path.handling");
            self::enum($surface['decided_by'], self::DECIDED_BY, "$path.decided_by");
            if (!is_array($surface['operations']) || !array_is_list($surface['operations'])) {
                throw self::refuseShape("$path.operations must be a list");
            }
            foreach ($surface['operations'] as $operation) {
                self::enum($operation, ProjectionVocabulary::OPERATIONS, "$path.operations[]");
            }
            if (array_key_exists('next_action', $surface)) {
                self::enum($surface['next_action'], ProjectionVocabulary::GAP_ACTIONS, "$path.next_action");
            }
        }

        foreach (self::rows($declarations, 'external_effects', 'contract.declarations') as $index => $effect) {
            $path = "contract.declarations.external_effects[$index]";
            self::closedKeys(
                $effect,
                ['id', 'surfaces', 'containment', 'effect_recovery_semantics', 'reason', 'decided_by'],
                ['restored_by', 'decided_at'],
                $path
            );
            self::nonEmptyString($effect['id'], "$path.id");
            self::nonEmptyString($effect['reason'], "$path.reason");
            self::enum($effect['containment'], ProjectionVocabulary::EFFECT_CONTAINMENT, "$path.containment");
            self::enum(
                $effect['effect_recovery_semantics'],
                ProjectionVocabulary::EFFECT_RECOVERY_SEMANTICS,
                "$path.effect_recovery_semantics"
            );
            self::enum($effect['decided_by'], self::DECIDED_BY, "$path.decided_by");
            self::vocabularyThisProfileNeverEarns($effect['containment'], "$path.containment");
            self::vocabularyThisProfileNeverEarns(
                $effect['effect_recovery_semantics'],
                "$path.effect_recovery_semantics"
            );
            if (!is_array($effect['surfaces']) || !array_is_list($effect['surfaces'])) {
                throw self::refuseShape("$path.surfaces must be a list");
            }
            if ($requireReviewedExternalEffects && $effect['decided_by'] === self::UNREVIEWED_DECIDED_BY) {
                // MUP §1.6: the declaration converts an unknown into a
                // known, bounded live effect, and only a human review can
                // do that. A generated placeholder must never be accepted
                // as authority.
                throw new CommandRefusalException(
                    'external_effect_unreviewed',
                    "declared external effect '" . (string) $effect['id'] . "' has not been reviewed",
                    'review the proposed external_effects entry, replace decided_by "unresolved" with '
                        . '"operator" and state the reviewed reason, then accept the proposal',
                    [['path' => $path]]
                );
            }
        }

        foreach (self::rows($declarations, 'journeys', 'contract.declarations') as $index => $journey) {
            $path = "contract.declarations.journeys[$index]";
            self::closedKeys(
                $journey,
                ['id', 'url', 'expect_status', 'expect_contains', 'affected_surfaces'],
                ['render_contains'],
                $path
            );
            self::nonEmptyString($journey['id'], "$path.id");
            self::nonEmptyString($journey['url'], "$path.url");
            if (!is_int($journey['expect_status'])) {
                throw self::refuseShape("$path.expect_status must be an integer");
            }
            self::nonEmptyString($journey['expect_contains'], "$path.expect_contains");
            if (!is_array($journey['affected_surfaces']) || !array_is_list($journey['affected_surfaces'])) {
                throw self::refuseShape("$path.affected_surfaces must be a list");
            }
        }

        foreach (self::rows($declarations, 'unsupported', 'contract.declarations') as $index => $row) {
            $path = "contract.declarations.unsupported[$index]";
            self::closedKeys($row, ['surface', 'operation', 'reason'], [], $path);
            self::nonEmptyString($row['surface'], "$path.surface");
            self::enum($row['operation'], ProjectionVocabulary::OPERATIONS, "$path.operation");
            self::nonEmptyString($row['reason'], "$path.reason");
        }

        if (array_key_exists('surface_labels', $declarations)) {
            $labels = $declarations['surface_labels'];
            if (!is_array($labels) || array_is_list($labels)) {
                throw self::refuseShape('contract.declarations.surface_labels must be an id -> label object');
            }
            foreach ($labels as $id => $label) {
                self::nonEmptyString($label, 'contract.declarations.surface_labels.' . (string) $id);
            }
        }
    }

    /** @param array<string,mixed> $stack */
    private static function validateStack(array $stack): void {
        $path = 'contract.declarations.stack';
        self::closedKeys($stack, ['wordpress', 'php', 'database', 'site_mode', 'plugins'], [], $path);
        self::validateRange(self::object($stack, 'wordpress', $path), "$path.wordpress");
        self::validateRange(self::object($stack, 'php', $path), "$path.php");

        $database = self::object($stack, 'database', $path);
        self::closedKeys($database, ['engine', 'min', 'max'], [], "$path.database");
        self::nonEmptyString($database['engine'], "$path.database.engine");
        self::validateBound($database['min'], "$path.database.min", false);
        self::validateBound($database['max'], "$path.database.max", true);

        self::enum($stack['site_mode'], self::SITE_MODES, "$path.site_mode");

        foreach (self::rows($stack, 'plugins', $path) as $index => $plugin) {
            $pluginPath = "$path.plugins[$index]";
            self::closedKeys($plugin, ['slug', 'min', 'max'], [], $pluginPath);
            self::nonEmptyString($plugin['slug'], "$pluginPath.slug");
            self::validateBound($plugin['min'], "$pluginPath.min", false);
            self::validateBound($plugin['max'], "$pluginPath.max", true);
        }
    }

    /** @param array<string,mixed> $range */
    private static function validateRange(array $range, string $path): void {
        self::closedKeys($range, ['min', 'max'], [], $path);
        self::validateBound($range['min'], "$path.min", false);
        self::validateBound($range['max'], "$path.max", true);
    }

    /**
     * A `max` of null is a deliberate, honest state, not a missing value: a
     * generated proposal can observe the floor a site is running today but
     * cannot invent the ceiling the operator is willing to support. The
     * review step in MUP §3.4 is where a ceiling arrives.
     */
    private static function validateBound(mixed $value, string $path, bool $nullable): void {
        if ($value === null && $nullable) {
            return;
        }
        self::nonEmptyString($value, $path);
    }

    /**
     * v2's two pins, and no third.
     *
     * `registry_sha256` addresses the reviewed dispositions the target read its
     * verdict from; `generated_from.dispositions_sha256` addresses the raw file
     * the proposing host held. Both are re-observable, which is what makes
     * pinning them mean something — the v1 `bundles[]` rows and the
     * `evidence_sha256`/`compatibility_sha256` inputs addressed generated
     * documents this tree no longer produces.
     *
     * @param array<string,mixed> $pins
     */
    private static function validateEvidencePins(array $pins): void {
        $path = 'contract.evidence_pins';
        self::closedKeys($pins, ['registry_sha256', 'generated_from'], [], $path);
        self::nonEmptyString($pins['registry_sha256'], "$path.registry_sha256");

        $from = self::object($pins, 'generated_from', $path);
        self::closedKeys($from, ['dispositions_sha256'], [], "$path.generated_from");
        self::nonEmptyString($from['dispositions_sha256'], "$path.generated_from.dispositions_sha256");
    }

    /** @param array<string,mixed> $attestation */
    private static function validateAttestation(array $attestation): void {
        $path = 'contract.attestation';
        self::closedKeys(
            $attestation,
            ['format', 'state', 'reason', 'approving_principal', 'policy_version', 'signature', 'expires_at'],
            [],
            $path
        );
        if (($attestation['format'] ?? null) !== self::ATTESTATION_FORMAT) {
            throw self::refuseFormat("$path.format must be " . self::ATTESTATION_FORMAT);
        }
        if (!in_array($attestation['state'], self::ATTESTATION_STATES, true)) {
            throw new CommandRefusalException(
                'attestation_state_invalid',
                "$path.state must be one of " . implode(' | ', self::ATTESTATION_STATES),
                'accept a freshly proposed contract; this profile writes attestation.state "unsigned"'
            );
        }
        self::nonEmptyString($attestation['reason'], "$path.reason");
        if ($attestation['state'] === 'unsigned') {
            foreach (['approving_principal', 'policy_version', 'signature', 'expires_at'] as $key) {
                if ($attestation[$key] !== null) {
                    throw self::refuseShape("$path.$key must be null while the attestation is unsigned");
                }
            }

            return;
        }
        foreach (['approving_principal', 'policy_version', 'signature', 'expires_at'] as $key) {
            self::nonEmptyString($attestation[$key], "$path.$key");
        }
    }

    /** @param array<string,mixed> $bindings */
    private static function validateEnvironmentBindings(array $bindings): void {
        $path = 'contract.environment_bindings';
        self::closedKeys($bindings, ['required'], [], $path);
        foreach (self::rows($bindings, 'required', $path) as $index => $row) {
            $rowPath = "$path.required[$index]";
            self::closedKeys($row, ['name', 'class', 'bound_in'], ['sensitivity'], $rowPath);
            self::nonEmptyString($row['name'], "$rowPath.name");
            self::nonEmptyString($row['bound_in'], "$rowPath.bound_in");
            // Only `env` state is bound per environment; anything else in
            // this list would be a value the contract is claiming to carry,
            // and MUP §3.1 is explicit that binding VALUES never live here.
            self::enum($row['class'], ['env'], "$rowPath.class");
            if (array_key_exists('sensitivity', $row)) {
                self::enum($row['sensitivity'], ['secret', 'pii', 'plain'], "$rowPath.sensitivity");
            }
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function closedKeys(array $value, array $required, array $optional, string $path): void {
        $known = array_merge($required, $optional);
        foreach (array_keys($value) as $key) {
            if (!in_array((string) $key, $known, true)) {
                throw new CommandRefusalException(
                    'contract_shape_invalid',
                    "$path carries the unrecognised key '" . (string) $key . "'",
                    'remove the key, or upgrade duo to a build that declares it; an unrecognised '
                        . 'declaration is silently inert and must never be treated as reviewed',
                    [['path' => $path, 'key' => (string) $key]]
                );
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw self::refuseShape("$path is missing the required key '$key'");
            }
        }
    }

    /**
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private static function object(array $parent, string $key, string $path): array {
        $value = $parent[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw self::refuseShape("$path.$key must be an object");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $parent
     * @return list<array<string,mixed>>
     */
    private static function rows(array $parent, string $key, string $path): array {
        $value = $parent[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw self::refuseShape("$path.$key must be a list");
        }
        $rows = [];
        foreach ($value as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw self::refuseShape("$path.$key" . '[' . (string) $index . '] must be an object');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function nonEmptyString(mixed $value, string $path): void {
        if (!is_string($value) || $value === '') {
            throw self::refuseShape("$path must be a non-empty string");
        }
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): void {
        if (!in_array($value, $allowed, true)) {
            throw new CommandRefusalException(
                'contract_vocabulary_invalid',
                "$path must be one of " . implode(' | ', $allowed),
                'use one of the listed values; the contract may not mint a new status word',
                [['path' => $path]]
            );
        }
    }

    /**
     * A declaration may not name a word this profile can never earn.
     * `sandboxed` needs egress control and `compensatable` needs a declared
     * compensation action; neither exists (MUP §1.5/§1.6, §8). Declaring one
     * would make the contract claim a containment or recovery property no
     * mechanism enforces.
     */
    private static function vocabularyThisProfileNeverEarns(mixed $value, string $path): void {
        if (in_array($value, ProjectionVocabulary::NEVER_EMITTED, true)) {
            throw new CommandRefusalException(
                'contract_vocabulary_unsupported',
                "$path declares '" . (string) $value . "', which nothing in this profile enforces",
                'declare a value this profile can prove, or defer the surface until the mechanism ships'
            );
        }
    }

    private static function refuseFormat(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'contract_format_invalid',
            $message,
            'regenerate the contract with duo contract propose, then review and accept it'
        );
    }

    private static function refuseShape(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'contract_shape_invalid',
            $message,
            'correct the named key in .duo/contract/contract.json, or regenerate the proposal'
        );
    }
}
