<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ContractProposal.php';
require_once __DIR__ . '/GapActions.php';
require_once __DIR__ . '/StackInventory.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The `wprism-assess-report/v1` document — the machine form of everything
 * `wprism assess` prints, and the only input `wprism contract propose` takes
 * (round-3 MUP §2.1, §3.4, §4.1).
 *
 * The document's schema is owned by its consumer,
 * `\WPrism\Orchestrator\ContractProposal`, which validates it as a closed key
 * set at every level. That is deliberate and it is why this class does not
 * re-declare the schema: two copies of a closed key set drift, and the copy
 * that matters is the one the contract refuses on. What lives here is the
 * *assembly* — the four blocks assess derives that nothing else can — plus
 * the two digest mechanics below.
 *
 * ## The digest, and why `rebind()` exists
 *
 * `assess_digest` is `sha256:` + the SHA-256 of the canonical encoding of
 * the report minus that one key (`ContractProposal::assessDigest()`), and
 * the proposal binds it so that `accept` can refuse a review that was
 * written about a different site.
 *
 * The digest therefore covers `generated_at`, because the digest covers
 * everything. Taken literally that would make every accept stale — two
 * assessments of an unchanged site differ by a timestamp and by nothing
 * else, and "the clock moved" is not "the site moved". `rebind()` is the
 * fix, and it belongs here rather than in the contract module: it restamps
 * a fresh report with the timestamp of the report being compared against
 * and recomputes the digest, so the comparison is over the *facts*. The
 * report stays internally consistent (its stated digest still matches its
 * own bytes), so the contract's own verification is untouched — it is the
 * choice of which two documents to compare that moved, and that choice is
 * the assessing command's.
 *
 * ## What the five blocks are
 *
 * `target` is the inventory's stack block verbatim (`StackInventory`).
 * `authority` is assess's own account of the access it had
 * (`StackInventory::authority()`); the contract reads none of it.
 * `surfaces` is `SurfaceCatalog`'s rows. `unknown`, `evidence` and
 * `dispositions` are assembled here because all three are pure re-shapings of
 * documents assess already holds and none is worth its own class.
 */
final class AssessReport {
    public const FORMAT = ContractProposal::ASSESS_REPORT_FORMAT;

    /**
     * The `dispositions` block's two sentences, owned by the schema's
     * consumer for the same reason `FORMAT` and `MAX_NAMES_SAMPLE` are: the
     * copy that matters is the one the contract validates against, and
     * `cli:Contract` may not reference `cli:Assess` (the module edge runs the
     * other way, docs/modules/cli-Contract.md).
     */
    public const DISPOSITIONS_AGREE_MEANING = ContractProposal::DISPOSITIONS_AGREE_MEANING;
    public const DISPOSITIONS_MISMATCH_MEANING = ContractProposal::DISPOSITIONS_MISMATCH_MEANING;

    /**
     * MUP §4.6 binds the machine document too: the human renderer is a
     * projection of this one, so an unbounded sample here would make the
     * human bound unreachable. The consumer refuses anything larger.
     */
    public const MAX_NAMES_SAMPLE = ContractProposal::MAX_NAMES_SAMPLE;

    /**
     * Build the document and bind its digest.
     *
     * @param array<string,mixed> $target `StackInventory::stack()`
     * @param array<string,mixed> $authority `StackInventory::authority()`
     * @param list<array<string,mixed>> $surfaces `SurfaceCatalog` rows
     * @param array<string,mixed> $unknown `unknown()`
     * @param array<string,mixed> $evidence `evidence()`
     * @param array<string,mixed> $dispositions `dispositions()`
     * @return array<string,mixed>
     */
    public static function build(
        string $environment,
        string $generatedAt,
        array $target,
        array $authority,
        array $surfaces,
        array $unknown,
        array $evidence,
        array $dispositions
    ): array {
        if ($environment === '' || $generatedAt === '') {
            throw self::refuse('an assess report needs an environment name and a generation timestamp');
        }
        $report = [
            'format' => self::FORMAT,
            'generated_at' => $generatedAt,
            'env' => $environment,
            'target' => $target,
            'authority' => $authority,
            'surfaces' => array_values($surfaces),
            'unknown' => $unknown,
            'evidence' => $evidence,
            'dispositions' => $dispositions,
            'assess_digest' => '',
        ];
        $report['assess_digest'] = ContractProposal::assessDigest($report);
        // Structural self-check against the consumer's own closed schema. A
        // report that cannot be proposed from is a defect worth finding on
        // the machine that produced it, not on the one that reviews it.
        ContractProposal::validateAssessReport($report);

        return $report;
    }

    /**
     * Restamp a report with another report's `generated_at` and rebind its
     * digest, so two assessments can be compared on their facts.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function rebind(array $report, string $generatedAt): array {
        if ($generatedAt === '') {
            throw self::refuse('an assess report cannot be rebound to an empty timestamp');
        }
        $report['generated_at'] = $generatedAt;
        $report['assess_digest'] = ContractProposal::assessDigest($report);

        return $report;
    }

    /** Canonical bytes, exactly as `--format=json` emits them. */
    public static function encode(array $report): string {
        return Canon::encode($report);
    }

    /**
     * Section 3 — the unknown / unclassified block (MUP §2.1 item 3).
     *
     * Names and counts only, never values: `Coverage` already reads every
     * option value to classify it and publishes none of them, and this
     * document is committed into a git repository. The sample is a list of
     * *origin-tagged names* so an operator can tell an invisible option
     * prefix from an undeclared table from a queued classification without
     * a second lookup, and it is bounded while the counts are exact — a
     * truncated sample beside a true count is honest; a truncated count is
     * not.
     *
     * `undeclared_tables_count` is T6 §3.7 item 2's fix. The names were
     * already in `names_sample`, but the COUNT was not published anywhere, so
     * the human `unknown:` block could not print a table line and the
     * next-actions roll-up could not include one. A count is what makes the
     * sample honest: the sample is bounded and the count never is.
     *
     * @param array<string,mixed> $inventory a `wprism-assess-inventory/v1` document
     * @return array{pending_count:int,invisible_names_count:int,undeclared_tables_count:int,names_sample:list<string>}
     */
    public static function unknown(array $inventory): array {
        $coverage = is_array($inventory['coverage'] ?? null) ? $inventory['coverage'] : [];
        $pending = is_array($inventory['pending'] ?? null) ? $inventory['pending'] : [];

        $names = [];
        foreach (($coverage['options']['invisible_groups'] ?? []) as $group) {
            if (is_array($group) && is_string($group['prefix'] ?? null)) {
                $names[] = 'option-prefix:' . $group['prefix'];
            }
        }
        foreach (($coverage['tables']['undeclared'] ?? []) as $table) {
            if (is_array($table) && is_string($table['logical_name'] ?? null)) {
                $names[] = 'table:' . $table['logical_name'];
            }
        }
        foreach (($pending['rows'] ?? []) as $row) {
            if (!is_array($row) || !is_string($row['section'] ?? null) || !is_string($row['key'] ?? null)) {
                continue;
            }
            $names[] = 'pending:' . $row['section'] . ':' . $row['key'];
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return [
            'pending_count' => is_int($pending['count'] ?? null) ? $pending['count'] : 0,
            'invisible_names_count' => is_int($coverage['options']['invisible_total'] ?? null)
                ? $coverage['options']['invisible_total']
                : 0,
            // Coverage's own exact total, not a count of the rows this
            // method could read: a row the agent published without a
            // `logical_name` is still an undeclared table on the site, and
            // reporting a smaller number because one row was unreadable is
            // exactly the truncated-count lie the docblock above forbids.
            'undeclared_tables_count' => is_int($coverage['tables']['undeclared_total'] ?? null)
                ? $coverage['tables']['undeclared_total']
                : 0,
            'names_sample' => array_slice($names, 0, self::MAX_NAMES_SAMPLE),
        ];
    }

    /**
     * The evidence pins this assessment observed (MUP §3.2's
     * `evidence_pins`, §3.4's refresh rule).
     *
     * `registry_sha256` is the TARGET's number, read out of the capability
     * report the target produced — the content address of the reviewed
     * dispositions its verdict was read from. `generated_from` is the host's
     * own copy of that same document, addressed by its raw bytes. The two are
     * from different machines on purpose: the pin that must flip when the site
     * changes is the target's hash, and it is the one `ContractProjection`
     * compares. Whether those two machines are holding the SAME reviewed
     * library is a different question, and it is answered in the sibling
     * `dispositions` block below rather than here — this block's shape is
     * copied verbatim into `contract.evidence_pins`, so a key added here would
     * be a contract wire change (issue #3484).
     *
     * There are no per-subject bundle pins any more. A claim's `evidence` is
     * the authored citation its disposition carries verbatim — a bundle schema
     * and named tests, with no digest and no status to expire — so a pin per
     * subject would have been a pin over a constant. Drift is detected at the
     * one place a change can actually happen: the reviewed document itself.
     *
     * @param array<string,array<string,mixed>> $registryReports
     * @param array{registry_sha256?:mixed,generated_from?:mixed} $provenance
     *        `AssessCommand::registryProvenance()`
     * @return array{registry_sha256:string,generated_from:array{dispositions_sha256:string}}
     */
    public static function evidence(array $registryReports, array $provenance): array {
        $registrySha = self::targetRegistrySha256($registryReports);
        if (!is_string($provenance['registry_sha256'] ?? null) || $provenance['registry_sha256'] === '') {
            throw self::refuse('the reviewed dispositions in this checkout have no content address');
        }
        $generatedFrom = is_array($provenance['generated_from'] ?? null) ? $provenance['generated_from'] : [];
        if (!is_string($generatedFrom['dispositions_sha256'] ?? null)
            || $generatedFrom['dispositions_sha256'] === '') {
            throw self::refuse('the reviewed dispositions in this checkout have no dispositions_sha256 provenance');
        }

        return [
            'registry_sha256' => $registrySha,
            'generated_from' => [
                'dispositions_sha256' => (string) $generatedFrom['dispositions_sha256'],
            ],
        ];
    }

    /**
     * The comparison `evidence()` had both halves of and never made (issue #3484).
     *
     * Since #477 `registry_sha256` is the content address of the reviewed
     * dispositions — `ManifestDispositions::sha256()`, sha256 over
     * `Canon::encode()` of the DECODED document. The target reports its own
     * (`AdapterRegistry::report()`, agent/src/Adapter/AdapterRegistry.php:530)
     * and `AssessCommand::registryProvenance()` computes this checkout's over
     * the same basis, deliberately so the two are comparable for equality and
     * whitespace in a checkout does not read as drift. They were assembled
     * three lines apart and never compared, so an operator could assess a site
     * against a reviewed library that is not the one the site is answering
     * from and read nothing about it.
     *
     * Both numbers are bare 64-hex from the same hash basis, so the comparison
     * is `hash_equals()` on the raw strings with nothing normalised — a
     * normalisation step here would be the place a real mismatch could be
     * papered over.
     *
     * @param array<string,array<string,mixed>> $registryReports
     * @param array{registry_sha256?:mixed} $provenance `AssessCommand::registryProvenance()`
     * @return array{host_registry_sha256:string,target_registry_sha256:string,agree:bool,meaning:string}
     */
    public static function dispositions(array $registryReports, array $provenance): array {
        $target = self::targetRegistrySha256($registryReports);
        if (!is_string($provenance['registry_sha256'] ?? null) || $provenance['registry_sha256'] === '') {
            throw self::refuse('the reviewed dispositions in this checkout have no content address');
        }
        $host = (string) $provenance['registry_sha256'];
        $agree = hash_equals($host, $target);

        return [
            'host_registry_sha256' => $host,
            'target_registry_sha256' => $target,
            'agree' => $agree,
            'meaning' => $agree ? self::DISPOSITIONS_AGREE_MEANING : self::DISPOSITIONS_MISMATCH_MEANING,
        ];
    }

    /**
     * Whether this assessment's two reviewed libraries are the same document.
     *
     * Read off the report rather than recomputed, because the report is what
     * the renderer projects and what the digest binds, and two answers to one
     * question is how a human view and a machine view start disagreeing. A
     * report with no block at all predates issue #3484 and is treated as
     * agreeing: it carries no comparison to have failed, and the only
     * documents in that state are stored proposals from an older build, which
     * `accept` already refuses as stale.
     *
     * @param array<string,mixed> $report
     */
    public static function dispositionsAgree(array $report): bool {
        $block = is_array($report['dispositions'] ?? null) ? $report['dispositions'] : null;

        return $block === null || ($block['agree'] ?? true) === true;
    }

    /**
     * The gate: refuse to turn a mismatched assessment into a contract.
     *
     * ## The window this must not break
     *
     * Host ≠ target is a LEGITIMATE state. `wprism adopt` assembles the package
     * library into `agent/` and tars `agent recovery`
     * (cli/src/Onboarding/Adopt.php), so a managed site answers from the
     * dispositions of the checkout it was last adopted from. The moment an
     * operator pulls a revision that edited a package-owned disposition,
     * their checkout is ahead of every site they have not re-adopted yet —
     * exactly the state docs/adoption.md's upgrade runbook walks through, and
     * its steps 1 and 2 (drain, then re-adopt every environment in a pair)
     * happen while the skew is open. So this may not be an unconditional
     * refusal: an operator diagnosing a site mid-upgrade must still get the
     * assessment. `wprism assess` completes, exit 0, with the mismatch as a
     * first-class block in the document and its own lines in the human view.
     *
     * ## The act that is unsafe, and why it is this one
     *
     * `ContractProposal::fromAssessReport()` copies the report's `evidence`
     * block verbatim into `contract.evidence_pins` (ContractProposal.php:143),
     * and that block is assembled from BOTH machines: `registry_sha256` is the
     * target's, `generated_from.dispositions_sha256` is the raw file hash of
     * the host's copy. `ApplicationContract::validateEvidencePins()` states
     * what the pair is supposed to mean — "the reviewed dispositions the
     * target read its verdict from" beside "the raw file the proposing host
     * held" — and never contemplates them being two different libraries.
     * Under skew that contract records host provenance next to declarations
     * (`declarations.surfaces`, `unsupported`, per-operation readiness) that
     * every one of them came out of the TARGET's capability reports via
     * `SurfaceCatalog`. It is a committed review artifact asserting a
     * provenance that is not true. That is the unsafe act, so the gate sits at
     * the mint: `AssessCommand::writeLocalArtifacts()` withholds
     * `proposed.json`, and `wprism contract propose` / `accept` refuse.
     *
     * Diagnosis is not the unsafe act and is not gated. Neither is
     * `projection.json`: its pins and its observations are both the TARGET's
     * own numbers at two points in time — `registry_sha256` and, since the
     * flip got narrower, each `manifest_pins[].adapter_digest`
     * (`ContractProjection::invalidation()`) — one machine, and both sides of
     * every one of those comparisons come out of `proposalSeed()` below and
     * the same target's report, so it stays honest under skew and assess keeps
     * regenerating it — leaving a stale projection beside a fresh assessment
     * would be the worse failure.
     *
     * `accept` gates BEFORE its staleness comparison on purpose: a checkout
     * that moved also moves `assess_digest` (the block is inside the digest),
     * so without this the operator would be told `contract_proposal_stale` —
     * "the site returns something different now" — about a site that did not
     * move at all.
     *
     * ## Direction of skew
     *
     * Not derivable. A sha256 carries no ordering, and
     * package-owned disposition documents move independently of
     * `WPRISM_AGENT_VERSION` (a reviewed-claim edit bumps no version), so there
     * is no second fact to break the tie. The remediation therefore names both
     * directions rather than guessing one.
     *
     * @param array<string,mixed> $report
     */
    public static function requireDispositionsAgree(array $report): void {
        if (self::dispositionsAgree($report)) {
            return;
        }
        /** @var array<string,mixed> $block */
        $block = $report['dispositions'];
        $host = self::shortSha($block['host_registry_sha256'] ?? '');
        $target = self::shortSha($block['target_registry_sha256'] ?? '');
        $remediation = 're-adopt this environment from this checkout, or check out the revision the site '
            . 'was adopted from; the two must name one reviewed library before a contract can pin it';

        throw new CommandRefusalException(
            'dispositions_mismatch',
            'this checkout ships reviewed dispositions ' . $host
                . ' but the target answered from ' . $target,
            $remediation,
            [[
                'code' => 'dispositions_mismatch',
                'host_registry_sha256' => $host,
                'target_registry_sha256' => $target,
                'message' => self::DISPOSITIONS_MISMATCH_MEANING,
                'remediation' => $remediation,
            ]]
        );
    }

    /**
     * The one number the target reported, from the first capability report
     * that carries one.
     *
     * All of them are the same document's hash — one target, one installed
     * library, one `ManifestDispositions` — so the first is the answer rather
     * than a sample; a report with none is a target this build cannot pin.
     *
     * @param array<string,array<string,mixed>> $registryReports
     */
    private static function targetRegistrySha256(array $registryReports): string {
        foreach ($registryReports as $report) {
            if (is_string($report['registry_sha256'] ?? null) && $report['registry_sha256'] !== '') {
                return $report['registry_sha256'];
            }
        }

        throw self::refuse('the target capability report carries no registry hash');
    }

    /**
     * MUP §5.2's bound: twelve hex is enough to compare two hashes by eye and
     * short enough that the human view never carries a 32-or-more-hex
     * identifier (the leak gate in sandbox/tests/lib/grind_lib.sh). The full
     * numbers are in `--format=json`.
     *
     * @param mixed $sha
     */
    private static function shortSha($sha): string {
        $value = is_string($sha) ? $sha : '';

        return strlen($value) > 12 ? substr($value, 0, 12) : $value;
    }

    /**
     * The four contract facts the report has no field for
     * (`ContractProposal::fromAssessReport()`'s `$seed`).
     *
     * All four are read from `wprism-assess-inventory/v1`, which the assessing
     * command already holds — inventing report keys for them would put
     * contract inputs into a document whose job is to describe a site.
     *
     * `environment_bindings.required` is deliberately empty in this
     * profile. The env-class option NAMES are not in the inventory: option
     * groups are grouped by declarant and class precisely because listing
     * them per name would be a listing bounded by the site's plugin set
     * (§4.6). `wprism status`'s `env_missing` checklist is the surface that
     * names them, and the review step in §3.4 is where they land in the
     * contract.
     *
     * @param array<string,mixed> $inventory
     * @param array{name:string,spec_version:int} $site
     * @return array<string,mixed>
     */
    public static function proposalSeed(array $inventory, array $site): array {
        $pins = [];
        foreach (($inventory['policy']['manifests'] ?? []) as $manifest) {
            if (!is_array($manifest) || !is_string($manifest['name'] ?? null)) {
                continue;
            }
            $digest = $manifest['adapter_digest'] ?? null;
            if (!is_string($digest) || $digest === '') {
                // A pin with no digest is not a pin. Refusing is loud on
                // purpose: silently dropping it would produce a contract
                // that pins fewer adapters than the site actually loads,
                // and the operator would review a shorter list than the one
                // in force.
                throw self::refuse(
                    'a pinned adapter reports no content digest, so it cannot be pinned in a contract'
                );
            }
            $pins[] = [
                'name' => $manifest['name'],
                'source' => is_string($manifest['source'] ?? null) ? $manifest['source'] : 'shipped',
                'adapter_digest' => $digest,
            ];
        }
        usort($pins, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $plugins = [];
        foreach (StackInventory::pluginRows($inventory) as $plugin) {
            if (($plugin['active'] ?? false) !== true) {
                continue;
            }
            $basename = (string) ($plugin['basename'] ?? '');
            if ($basename === '') {
                continue;
            }
            $plugins[] = [
                'slug' => str_contains($basename, '/')
                    ? explode('/', $basename, 2)[0]
                    : basename($basename, '.php'),
                'version' => (string) ($plugin['version'] ?? ''),
            ];
        }

        return [
            'site' => ['name' => $site['name'], 'spec_version' => $site['spec_version']],
            'manifest_pins' => $pins,
            'plugins' => $plugins,
            'environment_bindings' => ['required' => []],
        ];
    }

    private static function refuse(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'assess_report_unbuildable',
            $message,
            'rerun assess after repairing the target inventory or restoring the package-owned disposition documents'
        );
    }
}
