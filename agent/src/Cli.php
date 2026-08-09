<?php
namespace Duo;

require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/PlanExplanation.php';

use WP_CLI;

/**
 * wp duo <capture|refresh-export|plan|explain|apply|scope|capabilities|adapter-survey|orphans|deploy|code-stage|code-finalize|promotion-begin|promotion-abort|manifest-pin|identity-export|identity-import|journal-report|journal-reset>
 */
final class Cli {
    private const REFUSAL_FORMAT = 'duo-command-refusal/v1';

    /**
     * JSON is a command contract, including on refusal.  The old exception
     * whitelist emitted structured output only for compiler diagnostics;
     * every other failure fell through to WP_CLI::error(), so capture and
     * ordinary plan/apply gates returned human stderr to machine callers.
     * Keep typed diagnostics intact, but put every JSON-mode throwable in
     * one versioned envelope before WP-CLI can add its human "Error:" layer.
     */
    private static function halt_json_failure(\Throwable $t, array $assoc, string $command): void {
        if (!isset($assoc['json']) && ($assoc['format'] ?? '') !== 'json') {
            return;
        }

        if ($t instanceof RepositoryCompilationException
            || $t instanceof RepositoryAuthorizationException
            || $t instanceof CodeCompilationException) {
            $specific = $t->payload();
            $message = match (true) {
                $t instanceof RepositoryCompilationException => 'repository compilation refused this command',
                $t instanceof RepositoryAuthorizationException => 'repository authorization refused this command',
                default => 'code payload validation refused this command',
            };
            $remediation = self::refusal_remediation($command);
            $redacted = CommandRefusalException::containsSensitivePublicDetail($specific);
            if ($redacted) {
                // The established error code remains stable, but a diagnostic
                // is indivisible public evidence: if any nested field is
                // sensitive, omit the complete batch instead of attempting a
                // partial rewrite that could change its meaning or miss a
                // second secret-bearing field.
                $specific = [
                    'ok' => false,
                    'error' => (string) $specific['error'],
                ];
            }
        } elseif ($t instanceof CommandRefusalException) {
            $specific = $t->payload();
            $message = $t->publicMessage;
            $remediation = $t->remediation;
            $redacted = false;
        } elseif (self::publishable_refusal($command, $t->getMessage())) {
            // The engine's own refusal convention: a message deliberately
            // authored for the operator, prefixed `duo: ` at its throw site
            // (Deletion::capability(), Policy's validators, dozens more).
            // Redacting these broke the product's refusal transparency —
            // observed live as DUO-3398, where the ninja-forms conformance's
            // parent-deletion refusal ("deletion intent for table:nf3_forms
            // is unsupported…") surfaced as an unclassified redacted
            // envelope and the operator was sent to private evidence for a
            // refusal that WAS the public answer. The message is public;
            // everything else about the Throwable (class, previous chain,
            // file, trace) remains private, and the sensitivity screen both
            // here and in the final pass below still redacts a refusal that
            // embeds a secret-shaped value.
            $reasonCode = str_replace('-', '_', $command) . '_refused';
            $message = $t->getMessage();
            // Not the generic "inspect private operator evidence" floor: the
            // whole premise of this branch is that the message IS the public
            // answer.
            $remediation = 'the refusal message names the blocker; correct it, then retry the command';
            $specific = [
                'error' => $reasonCode,
                'diagnostics' => [[
                    'code' => $reasonCode,
                    'message' => $message,
                    'remediation' => $remediation,
                ]],
            ];
            $redacted = false;
        } else {
            $reasonCode = str_replace('-', '_', $command) . '_failed';
            $message = "$command refused at an unclassified safety gate";
            $remediation = self::refusal_remediation($command);
            $specific = [
                'error' => $reasonCode,
                'diagnostics' => [[
                    'code' => $reasonCode,
                    'message' => $message,
                    'remediation' => $remediation,
                ]],
            ];
            // A catch-all Throwable without the engine's own refusal prefix
            // is private operator evidence.  Never copy its message,
            // previous chain, file, or trace into public JSON.
            $redacted = true;
        }

        $payload = [
            'format' => self::REFUSAL_FORMAT,
            'ok' => false,
            'command' => $command,
            'error' => (string) $specific['error'],
            'reason_code' => (string) $specific['error'],
            'message' => $message,
            'remediation' => $remediation,
        ];
        if ($redacted) {
            $payload['details_redacted'] = true;
        }
        foreach ($specific as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }
        if (CommandRefusalException::containsSensitivePublicDetail($payload)) {
            // Defense in depth at the actual serialization boundary. Source
            // exceptions and legacy typed payloads are guarded above so safe
            // compatibility fields survive; this final pass ensures no new
            // envelope field or unexpected diagnostic value can bypass the
            // same policy later.
            $reasonCode = preg_match('/^[a-z][a-z0-9_]{2,63}$/', (string) ($payload['error'] ?? '')) === 1
                ? (string) $payload['error']
                : 'structured_refusal_redacted';
            $payload = [
                'format' => self::REFUSAL_FORMAT,
                'ok' => false,
                'command' => $command,
                'error' => $reasonCode,
                'reason_code' => $reasonCode,
                'message' => 'structured refusal details were redacted',
                'remediation' => 'inspect private operator evidence and recovery state before another attempt',
                'details_redacted' => true,
            ];
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            // Keep the one-value stdout contract even if an established typed
            // diagnostic contains a value PHP cannot serialize (for example
            // INF).  The fallback is deliberately constant and secret-free.
            $encoded = '{"format":"duo-command-refusal/v1","ok":false,"command":"'
                . str_replace(['\\', '"'], ['\\\\', '\\"'], $command)
                . '","error":"refusal_serialization_failed","reason_code":"refusal_serialization_failed",'
                . '"message":"structured refusal serialization failed","remediation":"inspect private operator evidence before another attempt","details_redacted":true}';
        }
        WP_CLI::line($encoded);
        WP_CLI::halt(1);
    }

    /**
     * May this Throwable's message be published verbatim in the JSON
     * refusal envelope?
     *
     * The `duo: ` prefix marks a refusal deliberately authored for the
     * operator — but the prefix alone is not proof of authorship: three
     * wrapper families re-prefix text the engine does NOT write (a
     * provider/regenerator's own exit tail per DUO-3282, and wpdb's
     * last_error on the deletion-guard paths — which Db.php's own header
     * warns "can echo option/meta payloads"). Those stay redacted here even
     * though their non-JSON rendering is unchanged. Beyond the shared
     * sensitivity screen, two extra shapes are excluded from this branch
     * only (scoped here, not in containsSensitivePublicDetail, so typed
     * refusal payloads keep their existing semantics): user logins
     * (`exact login '…'` — cli/README's redaction contract names logins
     * explicitly, and guard_personal_data() deliberately keeps them
     * operator-only) and absolute filesystem paths (the shared screen only
     * catches home-dir shapes). A refusal excluded here falls back to the
     * fully-redacted envelope — exactly the pre-DUO-3398 behavior, so
     * fail-closed is never a regression. A multi-line refusal also stays
     * redacted (the control-byte screen): the loud multi-line gates keep
     * their non-JSON rendering and are a candidate for typed refusals, not
     * for this branch.
     */
    /**
     * The commands whose duo:-prefixed raw refusals may publish. This is an
     * ALLOWLIST, and the rule is contract provenance, not command category:
     * these are the commands whose reachable refusal messages were audited
     * value-free at DUO-3398 (one of them load-bearing for the DUO-3328
     * ninja-forms certification contract). refresh-export and scope are
     * absent because DUO-3397 deliberately pinned blanket redaction for
     * them — a fresh reviewed decision this branch does not reverse —
     * and explain is absent because its own catch declares a stricter
     * value-free posture than any other command. A command not on this
     * list is redacted until someone audits it and adds it here WITH its
     * suite pins; silently inheriting publication is how the DUO-3398
     * fix-forward incident happened.
     */
    private const PUBLIC_REFUSAL_COMMANDS = [
        'apply', 'capture', 'code-finalize', 'code-stage', 'compile', 'deploy', 'plan',
    ];

    private static function publishable_refusal(string $command, string $message): bool {
        if (!in_array($command, self::PUBLIC_REFUSAL_COMMANDS, true)) {
            return false;
        }
        if (!str_starts_with($message, 'duo: ')) {
            return false;
        }
        foreach ([
            'duo: required manifest action',
            'duo: batch regenerator',
            'duo: deletion guard lock',
        ] as $wrapperPrefix) {
            if (str_starts_with($message, $wrapperPrefix)) {
                return false;
            }
        }
        if (preg_match("~\\bexact login '~", $message) === 1
            || preg_match('~(?:^|[\s"\'(=])(?:[A-Za-z]:[\\\\/]|/)[^\s"\')]+~', substr($message, 5)) === 1) {
            return false;
        }
        return !CommandRefusalException::containsSensitivePublicDetail(['message' => $message]);
    }

    private static function refusal_remediation(string $command): string {
        return match ($command) {
            'capture' => 'inspect private operator evidence and capture recovery state; classify, correct, or recover the blocker before another attempt',
            'compile' => 'fix every repository, policy, or code diagnostic before compiling again',
            'plan' => 'inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker',
            'explain' => 'run the existing capture, identity-recovery, or attachment-materialization gate if needed, then copy a current entity selector from plan and rerun explain',
            'apply' => 'inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase',
            'deploy' => 'inspect lifecycle and promotion evidence, then restore or recover the exact recorded code and state release',
            'code-stage' => 'inspect the staging receipt and promotion lease, then resume or recover the exact immutable artifact',
            'code-finalize' => 'inspect the staged receipt and promotion lease, then resume or recover the exact immutable artifact',
            'refresh-export' => 'inspect private operator evidence, then complete or recover the interrupted apply, promotion lease, identity, or code receipt before observing production again',
            'scope' => 'inspect private operator evidence, then compile the revision or correct the root selectors before resolving scope again',
            // DUO-3399: one reviewed arm per newly enveloped command, each
            // naming that command's own real blockers.  The default arm below
            // promises to "correct the named blocker" while the unclassified
            // path deliberately redacts every detail, so it contradicts itself
            // on exactly the path it would be used; a command only keeps it
            // when its failures genuinely have no reviewable shape.
            'promotion-begin' => 'inspect the recorded promotion lease holder, phase, and expiry plus any unresolved lifecycle attempt, then release that lease or restore its database checkpoint before acquiring again',
            'promotion-abort' => 'inspect the recorded promotion lease owner and artifact hash, then release the exact recorded lease or restore its database checkpoint before aborting again',
            'env-set' => 'inspect the loaded policy env declarations, then declare the option class "env" without sub_keys, supply a non-empty value, and set it again',
            'orphans' => 'inspect the declared authored_snapshot table and its structural ref columns, then name one listed orphan row and exactly one of --delete or --reparent before retrying',
            'verify-canonical' => 'inspect the parent apply frozen policy snapshot, compiled artifact, and expected artifact hash, then rerun verification from that exact apply',
            'journal-report' => 'inspect the provenance journal tables and the pinned manifests named by --manifests, then correct that selection before reporting again',
            'pending' => 'inspect the repository policy and provenance journal state, then correct the policy or ledger blocker before scanning the review queue again',
            'coverage' => 'inspect the repository policy and this environment\'s database access, then correct that blocker before reporting coverage again',
            'classify' => 'inspect the rejected --set spec and the repository policy file, then correct its section, key, class, or secret override before writing rules again',
            'lint' => 'inspect the repository policy and the captured state tree, then capture or correct the policy before linting again',
            'capabilities' => 'inspect the manifest disposition registry, the generated capability registry, and this repository\'s manifest pins, then correct that evidence before reporting capabilities again',
            default => "correct the named $command blocker, then retry the command",
        };
    }

    /**
     * Compile a canonical revision into Duo's immutable, content-addressed
     * apply artifact without reading or mutating the target environment.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--out=<path>] : Write the complete artifact as canonical JSON.
     * [--json]           : Emit the complete artifact as JSON.
     * [--format=<format>] : Output format. Accepts json.
     */
    public function compile($args, $assoc) {
        try {
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('compile', '--repo');
            $artifact = RepositoryCompiler::compile($repo, Policy::load($repo));
            if (!empty($assoc['out'])) {
                $artifact->write((string) $assoc['out']);
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'compile');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($artifact->export(), JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success(sprintf(
            'compiled artifact %s (revision %s, manifests %s)%s',
            $artifact->artifact_hash(), $artifact->revision_hash(), $artifact->manifest_hash(),
            !empty($assoc['out']) ? ' -> ' . $assoc['out'] : ''
        ));
    }

    /**
     * Acquire the host promotion lease before its database checkpoint. This
     * is intentionally hash-only: the host has already compiled and verified
     * the immutable outer artifact, while this command must remain available
     * to release/recover a lease even if the working repo later changes.
     *
     * ## OPTIONS
     * --promotion-owner=<token> : Required internal orchestrator owner token.
     * --artifact-hash=<sha256> : Required immutable artifact hash binding code and state.
     * [--json] : JSON summary.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand promotion-begin
     */
    public function promotion_begin($args, $assoc) {
        try {
            // Both lease selectors gate this command's advertised JSON
            // contract, so both refuse inside the structured boundary: an
            // orchestrator asking for --format=json read human stderr and no
            // record at all when either was absent.  Their operator prose is
            // unchanged, because human mode still prints the private message.
            $owner = $assoc['promotion-owner'] ?? throw CommandRefusalException::invalidArgument('promotion-begin', '--promotion-owner');
            $artifactHash = $assoc['artifact-hash'] ?? throw CommandRefusalException::invalidArgument('promotion-begin', '--artifact-hash');
            Ledger::ensure();
            $summary = PromotionLock::begin((string) $owner, (string) $artifactHash);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'promotion-begin');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success(sprintf(
            'promotion lease acquired for artifact %s until epoch %d',
            $summary['artifact_hash'],
            $summary['expires_at']
        ));
    }

    /**
     * Idempotently remove a host promotion lease after a failure or after a
     * checkpoint import has restored that checkpoint's lease row. It never
     * reads the mutable repository or mutates code/state payloads.
     *
     * ## OPTIONS
     * --promotion-owner=<token> : Required internal orchestrator owner token.
     * --artifact-hash=<sha256> : Required immutable artifact hash binding code and state.
     * [--json] : JSON summary.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand promotion-abort
     */
    public function promotion_abort($args, $assoc) {
        try {
            $owner = $assoc['promotion-owner'] ?? throw CommandRefusalException::invalidArgument('promotion-abort', '--promotion-owner');
            $artifactHash = $assoc['artifact-hash'] ?? throw CommandRefusalException::invalidArgument('promotion-abort', '--artifact-hash');
            Ledger::ensure();
            $summary = PromotionLock::abort((string) $owner, (string) $artifactHash);
        } catch (\Throwable $t) {
            // Compensating cleanup is the path an orchestrator runs after a
            // failure it already could not parse; a machine-unreadable
            // refusal here is the one that strands a lease.
            self::halt_json_failure($t, $assoc, 'promotion-abort');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success($summary['released']
            ? 'promotion lease aborted'
            : 'promotion lease already absent');
    }

    /**
     * Add/update the immutable code half in WP_CONTENT_DIR. Before lifecycle,
     * it never removes completed code; it may remove only an exact abandoned
     * staged-only MU file so a reviewed retry can recover WordPress bootstrap.
     *
     * ## OPTIONS
     * --repo=<path> : Site repo root (contains site.duo.json).
     * --compiled=<path> : Required frozen compiler artifact from host duo deploy.
     * --promotion-owner=<token> : Required internal orchestrator lease token.
     * --artifact-hash=<sha256> : Required host-observed outer artifact hash.
     * [--json] : JSON summary.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand code-stage
     */
    public function code_stage($args, $assoc) {
        try {
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('code-stage', '--repo');
            $compiledPath = $assoc['compiled'] ?? throw CommandRefusalException::invalidArgument('code-stage', '--compiled');
            $promotionOwner = $assoc['promotion-owner'] ?? throw CommandRefusalException::invalidArgument('code-stage', '--promotion-owner');
            $artifactHash = $assoc['artifact-hash'] ?? throw CommandRefusalException::invalidArgument('code-stage', '--artifact-hash');
            $policy = Policy::load($repo);
            $compiled = RepositoryCompiler::read_artifact((string) $compiledPath, $policy);
            $summary = Code::stage($repo, $compiled, [
                'promotion_owner' => (string) $promotionOwner,
                'artifact_hash' => (string) $artifactHash,
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'code-stage');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$summary['enabled']) {
            WP_CLI::success('code materialization is disabled for this legacy repository');
            return;
        }
        $recovered = count((array) ($summary['abandoned_stage_removed'] ?? []));
        WP_CLI::success(sprintf(
            'staged code revision %s (%d file(s)%s); promotion lease retained for finalize',
            $summary['code_revision'],
            $summary['files'],
            $recovered > 0 ? ", recovered $recovered abandoned staged MU file(s)" : ''
        ));
    }

    /**
     * Finalize code after lifecycle retirement/activation, pruning only owned component
     * roots and recording the completed code descriptor. Use
     * --promotion-hold when the following state apply must keep the lease.
     *
     * ## OPTIONS
     * --repo=<path> : Site repo root (contains site.duo.json).
     * --compiled=<path> : Required frozen compiler artifact from host duo deploy.
     * --promotion-owner=<token> : Required internal orchestrator lease token.
     * --artifact-hash=<sha256> : Required host-observed outer artifact hash.
     * [--promotion-hold] : Retain the lease for the following state apply.
     * [--json] : JSON summary.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand code-finalize
     */
    public function code_finalize($args, $assoc) {
        try {
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('code-finalize', '--repo');
            $compiledPath = $assoc['compiled'] ?? throw CommandRefusalException::invalidArgument('code-finalize', '--compiled');
            $promotionOwner = $assoc['promotion-owner'] ?? throw CommandRefusalException::invalidArgument('code-finalize', '--promotion-owner');
            $artifactHash = $assoc['artifact-hash'] ?? throw CommandRefusalException::invalidArgument('code-finalize', '--artifact-hash');
            $policy = Policy::load($repo);
            $compiled = RepositoryCompiler::read_artifact((string) $compiledPath, $policy);
            $summary = Code::finalize($repo, $compiled, [
                'promotion_owner' => (string) $promotionOwner,
                'artifact_hash' => (string) $artifactHash,
                'promotion_hold' => isset($assoc['promotion-hold']),
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'code-finalize');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$summary['enabled']) {
            WP_CLI::success('code materialization is disabled for this legacy repository');
            return;
        }
        WP_CLI::success(sprintf(
            'finalized code revision %s (%d file(s), %d removed)',
            $summary['code_revision'], $summary['files'], count($summary['removed'])
        ));
    }

    /**
     * Capture this environment's authored state into the site repo.
     *
     * ## OPTIONS
     * --repo=<path>    : Site repo root (contains site.duo.json).
     * [--out=<path>]   : Write the state tree elsewhere (determinism checks); skips ledger/media updates.
     * [--force-unresolved-refs] : drop an authored, ref-typed option whose target row exists but is out of
     *   policy scope the same way a dangling (deleted-target) reference is dropped, instead of aborting
     *   (task #73's loud-and-blocking gate; the honest fix is adding the target's post type/taxonomy to
     *   policy scope — this flag is the explicit best-effort escape hatch for when that isn't wanted).
     * [--scope-contract=<path>] : Consume one canonical duo-scope-contract/v1 file. The target recompiles
     *   the associated repository revision, derives a transaction-bound overlay, and preserves every
     *   excluded live row/media blob/tombstone byte-for-byte. Version 1 can delete only a selected live
     *   identity with reviewed deletion capability and no excluded inbound referrer; it cannot resurrect
     *   a selected tombstone or mint a new identity.
     * [--json]           : JSON summary (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function capture($args, $assoc) {
        try {
            if (isset($assoc['scope-contract']) && isset($assoc['scope-request-b64'])) {
                throw new \RuntimeException('duo: capture accepts one scope contract source');
            }
            $scopeRequest = null;
            if (isset($assoc['scope-contract'])) {
                $scopeRequest = ScopedStateOverlay::load_contract((string) $assoc['scope-contract']);
            } elseif (isset($assoc['scope-request-b64'])) {
                $bytes = base64_decode((string) $assoc['scope-request-b64'], true);
                if ($bytes === false || strlen($bytes) > 262144) {
                    throw new \RuntimeException('duo: capture received an invalid compact scope request');
                }
                $decoded = Canon::decode($bytes);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('duo: capture compact scope request must be one object');
                }
                $scopeRequest = $decoded;
            }
            $summary = Capture::run(
                $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('capture', '--repo'),
                $assoc['out'] ?? null,
                isset($assoc['force-unresolved-refs']),
                $scopeRequest
            );
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'capture');
            WP_CLI::error($t->getMessage());
        }
        // WP-CLI's dispatcher rewrites a bare --json into format=json and unsets
        // 'json' before the command runs (Runner::run_command()) — there is never
        // an $assoc['json'] key to isset() against.
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['notes'] ?? [] as $note) {
            WP_CLI::line('NOTE: ' . $note);
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        WP_CLI::success(sprintf(
            'captured %d posts, %d terms, %d menus, %d options file(s), %d deletion tombstone(s), %d media blob(s) -> %s',
            $summary['counts']['post'] ?? 0,
            $summary['counts']['term'] ?? 0,
            $summary['counts']['menu'] ?? 0,
            $summary['counts']['options'] ?? 0,
            $summary['counts']['deletion'] ?? 0,
            $summary['media'],
            $summary['state_dir']
        ));
    }

    /**
     * Export a strict, read-only canonical observation of live production.
     *
     * This is deliberately NOT a replacement for `wp duo capture`: capture
     * is the only gate allowed to mint/repair identity and publish state.
     * Refresh export requires that completed durable identity already exists,
     * binds records/maps/code receipt to one READ ONLY DB snapshot, and
     * refuses an interrupted apply or an active promotion lease.
     *
     * ## OPTIONS
     * --repo=<path> : Site repo root (contains site.duo.json and captured state/).
     * [--scope-contract=<path>] : Emit only the selected production projection plus explicit
     *   omitted-not-absent scope evidence. The contract is associated with this exact target repo.
     * [--json] : Emit the canonical production export as JSON.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand refresh-export
     */
    public function refresh_export($args, $assoc) {
        $format = isset($assoc['json']) ? 'json' : (string) ($assoc['format'] ?? '');
        if ($format !== '' && $format !== 'json') {
            WP_CLI::error('--format accepts only json');
        }
        try {
            if (!isset($assoc['repo']) || !is_string($assoc['repo']) || $assoc['repo'] === '') {
                throw CommandRefusalException::invalidArgument('refresh-export', '--repo');
            }
            if (isset($assoc['scope-contract']) && isset($assoc['scope-request-b64'])) {
                throw new \RuntimeException('duo: refresh-export accepts one scope contract source');
            }
            $scopeRequest = null;
            if (isset($assoc['scope-contract'])) {
                $scopeRequest = ScopedStateOverlay::load_contract((string) $assoc['scope-contract']);
            } elseif (isset($assoc['scope-request-b64'])) {
                $bytes = base64_decode((string) $assoc['scope-request-b64'], true);
                if ($bytes === false || strlen($bytes) > 262144) {
                    throw new \RuntimeException('duo: refresh-export received an invalid compact scope request');
                }
                $decoded = Canon::decode($bytes);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('duo: refresh-export compact scope request must be one object');
                }
                $scopeRequest = $decoded;
            }
            $export = RefreshExport::run($assoc['repo'], false, $scopeRequest);
        } catch (\Throwable $t) {
            // One shared refusal formatter, not a private JSON catch path: a
            // refresh export refuses on repository paths, database snapshot
            // state, and provider detail, so serializing the raw Throwable
            // message here published exactly the operator-only bytes the
            // common envelope redacts.  The unclassified reason code the
            // formatter derives from this command name is the established
            // refresh_export_failed, so machine callers keep reading it.
            self::halt_json_failure($t, $assoc, 'refresh-export');
            WP_CLI::error($t->getMessage());
        }
        if ($format === 'json') {
            WP_CLI::line(rtrim(Canon::encode($export), "\n"));
            return;
        }
        WP_CLI::success(sprintf(
            'exported production snapshot %s (%d semantic record(s), %d media blob(s))',
            $export['snapshot_hash'],
            count($export['records']),
            count($export['media'])
        ));
    }

    /**
     * Export the database-bound identity ledger beside a database backup.
     *
     * ## OPTIONS
     * --repo=<path>
     * --out=<path>
     *
     * @subcommand identity-export
     */
    public function identity_export($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        $out = $assoc['out'] ?? WP_CLI::error('--out required');
        try {
            $artifact = IdentityBackup::create($repo);
            Canon::write_file($out, Canon::encode($artifact));
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        WP_CLI::success(sprintf(
            'exported %d identity mappings and %d sync states -> %s',
            count($artifact['maps']), count($artifact['states']), $out
        ));
    }

    /**
     * Restore a verified identity sidecar into its matching database backup.
     *
     * ## OPTIONS
     * --repo=<path>
     * --in=<path>
     *
     * @subcommand identity-import
     */
    public function identity_import($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        $in = $assoc['in'] ?? WP_CLI::error('--in required');
        try {
            $summary = IdentityBackup::restore($repo, $in);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        WP_CLI::success(sprintf(
            'restored %d identity mappings and %d sync states (applied revision %s)',
            $summary['maps'], $summary['states'], $summary['applied_revision'] ?: 'none'
        ));
    }

    /**
     * Preview what apply would do (terraform-style).
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>] : e.g. terms,posts,menus
     * [--force-unresolved-refs] : see `duo capture`'s option of the same name — plan's own drift
     *   detection captures the live environment too, so it hits the identical gate.
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--promotion-owner=<token>] : Internal orchestrator lease token shared with deploy.
     * [--artifact-hash=<sha256>] : Internal host-observed artifact hash; required with orchestrated promotion-owner.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function plan($args, $assoc) {
        try {
            $plan = Apply::plan($assoc['repo'] ?? throw CommandRefusalException::invalidArgument('plan', '--repo'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                'compiled' => $assoc['compiled'] ?? '',
                'promotion_owner' => $assoc['promotion-owner'] ?? '',
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'plan');
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($plan, JSON_UNESCAPED_SLASHES));
            return;
        }
        $kinds = [
            'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
            'collision', 'delete', 'delete_conflict', 'deleted',
        ];
        foreach ($kinds as $kind) {
            foreach ($plan[$kind] as $r) {
                $line = strtoupper(str_pad($kind, 9)) . ' ' . ($r['path'] ?? ($r['type'] . ' ' . $r['uuid']));
                // Display-only: JSON keeps the raw authored title; the line
                // renderer collapses whitespace so one row stays one line.
                if (is_string($r['title'] ?? null) && trim($r['title']) !== '') {
                    $line .= " '" . trim((string) preg_replace('/\s+/', ' ', $r['title'])) . "'";
                }
                if (isset($r['blocked'])) {
                    $line .= '  [BLOCKED: ' . $r['blocked'] . ']';
                }
                WP_CLI::line($line);
                if (is_string($r['uuid'] ?? null) && $r['uuid'] !== '') {
                    WP_CLI::line(
                        '  EXPLAIN wp duo explain '
                        . PlanExplanation::selectorForOutput($kind, $r['uuid'])
                        . ' --repo=<repo>'
                    );
                }
                foreach ($r['annotations'] ?? [] as $annotation) {
                    WP_CLI::line('  ' . $annotation);
                }
                foreach ($r['widget_deletes'] ?? [] as $widget) {
                    $origin = !empty($widget['unmanaged']) ? 'unmanaged target default' : 'mapped target widget';
                    WP_CLI::line(
                        "  WIDGET_DELETE {$widget['type']} {$widget['uuid']} ($origin; absent from declared sidebar file)"
                    );
                }
                if ($kind === 'conflict' || $kind === 'delete_conflict') {
                    foreach (self::plan_conflict_view_lines($r) as $detail) {
                        WP_CLI::line('  ' . $detail);
                    }
                }
            }
        }
        // code_mismatch (docs/proposals/code-half.md §3.2): a different row
        // shape (issue/kind/plugin-or-theme/message, no uuid/path) than the
        // $kinds loop above, so it gets its own rendering rather than being
        // folded into that loop. A descriptor's code_revision_stale row is
        // deliberately non-forceable: it is the code-before-state ordering
        // witness, not an ordinary lifecycle compatibility mismatch.
        $codeMismatch = $plan['code_mismatch'] ?? [];
        $codeRevisionStale = array_values(array_filter(
            $codeMismatch,
            static fn(array $r): bool => ($r['issue'] ?? null) === 'code_revision_stale'
        ));
        $forceableCodeMismatch = array_values(array_filter(
            $codeMismatch,
            static fn(array $r): bool => ($r['issue'] ?? null) !== 'code_revision_stale'
        ));
        foreach ($codeRevisionStale as $r) {
            WP_CLI::line('CODE_REVISION_STALE code payload');
            WP_CLI::line('  ' . ($r['message'] ?? 'run the host duo deploy workflow'));
        }
        foreach ($forceableCodeMismatch as $r) {
            WP_CLI::line('CODE_MISMATCH ' . strtoupper($r['issue']) . ' ' . ($r['plugin'] ?? $r['theme'] ?? '?'));
            WP_CLI::line('  ' . $r['message']);
        }
        foreach ($plan['code_drift'] ?? [] as $r) {
            WP_CLI::line('CODE_DRIFT ' . strtoupper($r['kind']) . ' ' . ($r['plugin'] ?? $r['theme'] ?? '?'));
            WP_CLI::line('  ' . $r['message']);
        }
        foreach ($plan['incomplete_apply'] ?? [] as $r) {
            WP_CLI::line('INCOMPLETE_APPLY ' . $r['reason']);
        }
        foreach ($plan['incomplete_lifecycle'] ?? [] as $r) {
            WP_CLI::line(
                'INCOMPLETE_LIFECYCLE ' . ($r['phase'] ?? '?') . ' ' . ($r['entity'] ?? '?')
                . ' at ' . ($r['before_hash'] ?? '?')
            );
            WP_CLI::line('  ' . ($r['reason'] ?? 'exact checkpoint recovery required'));
        }
        foreach ($plan['missing_user'] ?? [] as $r) {
            WP_CLI::line("MISSING_USER {$r['path']} (exact login '{$r['login']}')");
        }
        foreach ($plan['skipped_user_meta'] ?? [] as $r) {
            WP_CLI::line("SKIPPED_USER_META {$r['path']} (exact login '{$r['login']}')");
        }
        foreach ($plan['adapter_dispositions'] ?? [] as $r) {
            // DUO-3314: the source rides on the row itself. An out-of-tree
            // adapter is conspicuous here rather than looking like a shipped
            // adapter that failed review — mirrored in cli/src/PlanSummary.php
            // so `duo status` and a plain `wp duo plan` never differ.
            WP_CLI::line(
                'CAPABILITY_' . strtoupper((string) ($r['status'] ?? 'unsupported')) . ' '
                . ($r['name'] ?? '?') . ' [source=' . ($r['source'] ?? 'shipped')
                . ' tier=' . ($r['trust_tier'] ?? 'unknown')
                . ' certification=' . ($r['certification'] ?? 'registry')
                . '] [' . ($r['code'] ?? 'not_certified') . ']: '
                . ($r['reason'] ?? 'not certified')
            );
            if (($r['remediation'] ?? '') !== '') {
                WP_CLI::line('  remediation: ' . $r['remediation']);
            }
        }
        // DUO-3339: provider negotiation's problem rows, reported at plan for
        // the first time (spec/repo-format.md's bound (4)). Row shape is
        // Providers::problem()'s — {provider,manifest,plugin,code,expected,
        // found,remediation,message}, no uuid/path — so it gets its own block,
        // mirrored in cli/src/PlanSummary.php exactly like the block above it
        // so `duo status` and a plain `wp duo plan` never differ.
        foreach ($plan['provider_problems'] ?? [] as $r) {
            WP_CLI::line(
                'PROVIDER_PROBLEM ' . ($r['provider'] ?? '?')
                . ' [manifest=' . ($r['manifest'] ?? '?') . ' plugin=' . ($r['plugin'] ?? '?')
                . '] [' . ($r['code'] ?? 'unknown') . ']: expected ' . ($r['expected'] ?? '?')
                . ', found ' . ($r['found'] ?? '?')
            );
            if (($r['remediation'] ?? '') !== '') {
                WP_CLI::line('  remediation: ' . $r['remediation']);
            }
        }
        // regen_pending (DUO-3234, design review addition 1): a derived
        // table with a hard per-entity availability dependency whose
        // post-apply verification failed and hasn't resolved yet — see
        // Apply::regen_dependencies()'s own docblock. Same shape/reasoning
        // as incomplete_apply immediately above; mirrored in
        // cli/src/PlanSummary.php's render() so `duo status` and a plain
        // `wp duo plan` never give an operator different advice.
        foreach ($plan['regen_pending'] ?? [] as $r) {
            WP_CLI::line('REGEN_PENDING ' . ($r['path'] ?? ($r['type'] . ' ' . $r['uuid'])) . " (post type '{$r['post_type']}')");
        }
        // regen_context (DUO-3342): an outstanding pre-delete inventory or
        // pre-move receipt whose derived-state repair no consumer has verified
        // yet. Same shape and reasoning as regen_pending immediately above —
        // and it became worth surfacing for the same reason: those markers now
        // SURVIVE a failed apply rather than being swept, so an operator can
        // see one standing between a failure and its retry.
        foreach ($plan['regen_context'] ?? [] as $r) {
            WP_CLI::line('REGEN_CONTEXT ' . ($r['path'] ?? ($r['type'] . ' ' . $r['uuid']))
                . " (post type '{$r['post_type']}', {$r['kind']} receipt)");
        }
        // env_missing (DUO-3232): a manifest-declared `class: "env"` option
        // unset on this environment — see Apply::build_plan()'s own
        // docblock. No uuid/path (row shape is {name,required}), so this
        // does not fit the $kinds loop above. Mirrored in
        // cli/src/PlanSummary.php's render() so `duo status` and a plain
        // `wp duo plan` never give an operator different advice.
        foreach ($plan['env_missing'] ?? [] as $r) {
            $flag = !empty($r['required']) ? 'required' : 'optional';
            WP_CLI::line('ENV_MISSING ' . ($r['name'] ?? '?') . " ($flag)");
        }
        foreach ($plan['uploads_inventory'] ?? [] as $r) {
            $root = ($r['derivative_directory'] ?? '') !== ''
                ? $r['derivative_directory'] . '/' . $r['derivative_basename_prefix'] . '*'
                : $r['derivative_basename_prefix'] . '*';
            WP_CLI::line('UPLOAD_MUTATION ' . ($r['original_path'] ?? '?') . " (derivatives: $root)");
        }
        foreach ($plan['effects_inventory'] ?? [] as $r) {
            $effect = (array) ($r['effect'] ?? []);
            WP_CLI::line(sprintf(
                'EFFECT %-11s %-12s %-20s %s:%s',
                (string) ($r['phase'] ?? '?'),
                (string) ($effect['mode'] ?? '?'),
                (string) ($effect['id'] ?? '?'),
                (string) (($effect['selector'] ?? [])['type'] ?? '?'),
                (string) (($effect['selector'] ?? [])['value'] ?? '?')
            ));
        }
        foreach ($plan['warnings'] ?? [] as $w) {
            WP_CLI::warning($w);
        }
        $counts = implode(', ', array_map(fn($k) => count($plan[$k]) . " $k", $kinds));
        $counts .= ', ' . count($plan['code_mismatch'] ?? []) . ' code_mismatch';
        $counts .= ', ' . count($plan['code_drift'] ?? []) . ' code_drift';
        $counts .= ', ' . count($plan['incomplete_apply'] ?? []) . ' incomplete_apply';
        $counts .= ', ' . count($plan['incomplete_lifecycle'] ?? []) . ' incomplete_lifecycle';
        $counts .= ', ' . count($plan['regen_pending'] ?? []) . ' regen_pending';
        $counts .= ', ' . count($plan['regen_context'] ?? []) . ' regen_context';
        $counts .= ', ' . count($plan['env_missing'] ?? []) . ' env_missing';
        $counts .= ', ' . count($plan['missing_user'] ?? []) . ' missing_user';
        $counts .= ', ' . count($plan['skipped_user_meta'] ?? []) . ' skipped_user_meta';
        $counts .= ', ' . count($plan['uploads_inventory'] ?? []) . ' upload_mutations';
        $counts .= ', ' . count($plan['effects_inventory'] ?? []) . ' declared_effects';
        $counts .= ', ' . count($plan['adapter_dispositions'] ?? []) . ' adapter_dispositions';
        $counts .= ', ' . count($plan['provider_problems'] ?? []) . ' provider_problems';
        WP_CLI::success("plan: $counts");
        if ($plan['drift']) {
            WP_CLI::warning('environment drift detected — capture-first workflow recommended');
        }
        if ($codeRevisionStale) {
            WP_CLI::warning('code_revision_stale — run host `duo deploy <env>`; force flags cannot bypass this ordering invariant');
        }
        if ($forceableCodeMismatch) {
            WP_CLI::warning('code_mismatch findings — duo apply will refuse until resolved (or run with --force-code-mismatch)');
        }
        if (!empty($plan['code_drift'])) {
            WP_CLI::warning('code_drift findings — duo apply will refuse until resolved (or run with --force-code-drift)');
        }
        if (!empty($plan['regen_pending'])) {
            WP_CLI::warning('regen_pending markers outstanding — the next duo apply will retry them automatically');
        }
        if (!empty($plan['regen_context'])) {
            WP_CLI::warning('regen_context receipts outstanding — the next duo apply that reaches their surface will '
                . 'redeliver them to their declared consumer');
        }
        if (!empty($plan['adapter_dispositions'])) {
            WP_CLI::warning('capability registry blocker(s) selected — readiness is not green and host promotion will refuse');
        }
        if (!empty($plan['provider_problems'])) {
            WP_CLI::warning(
                'declared provider capabilities are missing or incompatible here — duo apply refuses before mutation '
                . 'on any of these its own work reaches'
            );
        }
        if (!empty($plan['missing_user'])) {
            WP_CLI::warning('required exact login(s) missing — duo apply will refuse before target mutation');
        }
        $envMissingRequired = array_filter($plan['env_missing'] ?? [], fn($r) => !empty($r['required']));
        if ($envMissingRequired) {
            WP_CLI::warning('required env value(s) missing — provision with `wp duo env-set --name=<name> --value=<value>` (or --stdin) before promoting');
        }
    }

    /**
     * Explain one entity action from a freshly-observed plan.
     *
     * ## OPTIONS
     * <selector> : `<bucket>:<entity-key>` copied from the current plan row.
     * --repo=<path>
     * [--adopt-by-slug=<kinds>] : Match the plan invocation being explained.
     * [--force-unresolved-refs] : Match the plan invocation being explained.
     * [--compiled=<path>] : Consume a previously emitted compiler artifact.
     * [--format=<format>] : Output format. Accepts json.
     */
    public function explain($args, $assoc) {
        try {
            if (count($args) !== 1 || !is_string($args[0] ?? null) || $args[0] === '') {
                throw CommandRefusalException::invalidArgument('explain', '<bucket>:<entity-key>');
            }
            $report = Apply::explain(
                $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('explain', '--repo'),
                $args[0],
                [
                    'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                    'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                    'compiled' => $assoc['compiled'] ?? '',
                ]
            );
            if (($assoc['format'] ?? '') === 'json') {
                $encoded = json_encode(
                    $report,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                WP_CLI::line($encoded);
                return;
            }
            foreach (PlanExplanation::render($report) as $line) {
                WP_CLI::line($line);
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'explain');
            // Explain's human contract is value-free too. Unlike the older
            // operator-oriented commands, it never forwards exception text,
            // target identities, or repository paths merely because JSON was
            // not requested. A reviewed refusal can keep its public guidance;
            // every other failure is diagnosed through plan/capture instead.
            if ($t instanceof CommandRefusalException) {
                WP_CLI::error($t->publicMessage . '; ' . $t->remediation);
            }
            WP_CLI::error(
                'duo: explain refused at a private safety gate; run plan or capture for operator diagnosis, then rerun explain'
            );
        }
    }

    /**
     * Render the versioned three-way evidence without exposing canonical
     * entity values. JSON retains full hashes for exact correlation; human
     * output uses a bounded prefix so WordPress names and semantic roles stay
     * primary. Keep this wording in lockstep with PlanSummary::render().
     *
     * @return list<string>
     */
    private static function plan_conflict_view_lines(array $row): array {
        $view = $row['conflict_view'] ?? null;
        if (!is_array($view) || ($view['format'] ?? null) !== 'duo-plan-conflict/v1') {
            return [];
        }
        $base = (array) ($view['base'] ?? []);
        $repository = (array) ($view['repository'] ?? []);
        $target = (array) ($view['target'] ?? []);
        $lines = [
            'WHY ' . ($view['reason_code'] ?? 'plan_conflict'),
            'BASE last-synced: ' . ($base['state'] ?? 'unknown')
                . ' ' . self::plan_hash_label($base['content_hash'] ?? null),
            'REPOSITORY intent=' . ($repository['intent'] ?? 'unknown')
                . ' state=' . self::plan_hash_label($repository['content_hash'] ?? null)
                . ' expected-base=' . self::plan_hash_label($repository['expected_base_hash'] ?? null),
            'TARGET observation: intent=' . ($target['intent'] ?? 'unknown')
                . ' state=' . ($target['state'] ?? 'unknown')
                . ' ' . self::plan_hash_label($target['content_hash'] ?? null),
            'SAFE CHOICE reconcile_in_repository: preserve both intents; capture the target change, resolve it in the repository, then re-plan',
        ];
        $choices = array_values(array_filter(
            (array) ($view['choices'] ?? []),
            static fn($choice): bool => is_array($choice) && ($choice['id'] ?? null) === 'apply_repository'
        ));
        if ($choices) {
            $choice = $choices[0];
            $requires = implode(' ', array_map('strval', (array) ($choice['requires'] ?? [])));
            $effect = ($choice['effect'] ?? '') === 'delete_target_authored_state'
                ? 'delete target authored state'
                : 'replace target authored state';
            $lines[] = 'DESTRUCTIVE OVERRIDE apply_repository'
                . ($requires === '' ? '' : " ($requires)") . ": $effect";
        }
        return $lines;
    }

    private static function plan_hash_label(mixed $hash): string {
        if (!is_string($hash) || $hash === '') {
            return 'none';
        }
        return 'sha256:' . substr($hash, 0, 12);
    }

    /**
     * Provision one manifest-declared `class: "env"` option value directly
     * into this environment — DUO-3232. Deliberately outside the ordinary
     * capture/apply pipeline: env values are never captured, so there is no
     * repo-side record for this command to reconcile against, only a
     * direct write, gated by Apply::set_env_option() to option names the
     * loaded policy actually declared `class: "env"` (never an arbitrary
     * option). See `wp duo plan`'s env_missing bucket for the current
     * per-environment checklist this command exists to satisfy.
     *
     * ## OPTIONS
     * --repo=<path>
     * --name=<name>       : Must be declared class="env" in a loaded manifest or site.duo.json.
     * [--value=<value>]   : Plain value — scriptable/CI use. Mutually exclusive with --stdin. A
     *   value passed this way lands in shell history/process listings on most systems; prefer
     *   --stdin for anything genuinely secret when run interactively.
     * [--stdin]           : Read the value interactively from STDIN with terminal echo disabled
     *   (`stty -echo`, restored afterward) — never printed back. Mutually exclusive with --value.
     *   Deliberately NOT named --prompt: wp-cli itself reserves that flag globally (it triggers
     *   wp-cli's own generic per-parameter prompting and is consumed before any command ever sees
     *   it in $assoc — confirmed live, not assumed; an isset($assoc['prompt']) check is silently
     *   always false), so this command needs its own, non-colliding name. The "value for '<name>':
     *   " prompt itself writes to STDERR, never STDOUT (found live: printing it to STDOUT
     *   interleaved with --format=json's own output and broke every caller parsing stdout as
     *   JSON) — safe to pipe `wp duo env-set ... --stdin --format=json` and parse stdout as pure
     *   JSON even while a prompt is also being shown.
     * [--json]            : JSON output (wp-cli rewrites this to --format=json). The value is
     *   never included in the response, only in this command's own request.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand env-set
     */
    public function env_set($args, $assoc) {
        try {
            // Every gate moves inside the structured boundary, including the
            // two that reject a contradictory or absent value source: this
            // command advertises --format=json, and a caller that got neither
            // a record nor a reason could not tell "wrong flags" from "policy
            // refused the option".  The masked read stays here as well — its
            // prompt is STDERR-only, so stdout keeps its exactly-one-value
            // contract, and a failed read now refuses through this envelope
            // instead of an unformatted throw.
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('env-set', '--repo');
            $name = $assoc['name'] ?? throw CommandRefusalException::invalidArgument('env-set', '--name');
            $hasValue = array_key_exists('value', $assoc);
            $hasStdin = isset($assoc['stdin']);
            if ($hasValue && $hasStdin) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'env-set accepts exactly one value source',
                    'pass exactly one of --value or --stdin and rerun env-set',
                    [],
                    'pass exactly one of --value or --stdin, not both'
                );
            }
            if (!$hasValue && !$hasStdin) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'env-set requires a value source',
                    'pass one of --value=<value> or --stdin and rerun env-set',
                    [],
                    'one of --value=<value> or --stdin is required'
                );
            }
            $value = $hasStdin ? self::read_masked_value("value for '$name': ") : (string) $assoc['value'];
            $result = Apply::set_env_option($repo, (string) $name, $value);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'env-set');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($result, JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success(sprintf(
            "%s '%s' (%s)",
            $result['previously_set'] ? 'updated' : 'set',
            $result['name'],
            $result['previously_set'] ? 'replaced an existing value' : 'was previously unset'
        ));
    }

    /**
     * Read one line from STDIN with the terminal's echo disabled, so a
     * secret value never appears on-screen or in scrollback — the standard
     * portable technique (`stty -echo` around the read, unconditionally
     * restored via try/finally even if the read itself throws). A best
     * effort only: stty silently no-ops when STDIN isn't a real terminal
     * (piped/redirected input, common under CI), which is the correct
     * fallback, not a failure — there is no terminal echo to suppress in
     * that case, and env-set has no way to distinguish "a human is
     * watching" from "a script is feeding stdin" other than this.
     *
     * The prompt and the trailing newline both go straight to STDERR
     * (fwrite, deliberately bypassing WP_CLI::out()/::line(), which write
     * STDOUT) — found live, not assumed: with WP_CLI::out() here,
     * `--stdin --format=json` interleaved the prompt text ahead of the
     * JSON on stdout, breaking every caller that parses stdout as JSON
     * (this command's own regress_env_set.sh included). A prompt is UI
     * chrome for whichever human is at the keyboard, never response data;
     * it must stay off stdout regardless of --format, the same convention
     * curl/ssh use for their own interactive password prompts.
     */
    private static function read_masked_value(string $prompt): string {
        fwrite(STDERR, $prompt);
        $isPosix = stripos(PHP_OS, 'WIN') === false && function_exists('shell_exec');
        if ($isPosix) {
            shell_exec('stty -echo 2>/dev/null');
        }
        try {
            $line = fgets(STDIN);
        } finally {
            if ($isPosix) {
                shell_exec('stty echo 2>/dev/null');
            }
        }
        fwrite(STDERR, "\n"); // the operator's Enter produced no visible newline while echo was off
        return $line === false ? '' : trim($line);
    }

    /**
     * Materialize the repo state into this environment.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>]
     * [--with-deletes]
     * [--force-delete-referenced] : override referential delete guards.
     * [--force-theirs]
     * [--force-code-mismatch] : override the cross-partition invariant's missing_in_code/outside_version_range block.
     * [--force-code-drift] : override the code_drift block (DUO-3231) — installed plugin/theme versions changed
     *   outside 'duo deploy'/'duo capture' since the last recorded baseline.
     * [--force-unresolved-refs] : see `duo capture`'s option of the same name — apply's own drift
     *   detection captures the live environment too, so it hits the identical gate.
     * [--default-author=<login>]
     * [--revision=<rev>]
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--promotion-owner=<token>] : Internal orchestrator lease token shared with deploy.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function apply($args, $assoc) {
        try {
            $summary = Apply::apply($assoc['repo'] ?? throw CommandRefusalException::invalidArgument('apply', '--repo'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                'with_deletes' => isset($assoc['with-deletes']),
                'force_delete_referenced' => isset($assoc['force-delete-referenced']),
                'force_theirs' => isset($assoc['force-theirs']),
                'force_code_mismatch' => isset($assoc['force-code-mismatch']),
                'force_code_drift' => isset($assoc['force-code-drift']),
                'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                'default_author' => $assoc['default-author'] ?? '',
                'revision' => $assoc['revision'] ?? '',
                'compiled' => $assoc['compiled'] ?? '',
                'promotion_owner' => $assoc['promotion-owner'] ?? '',
                'artifact_hash' => $assoc['artifact-hash'] ?? '',
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'apply');
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        foreach ($summary['drift'] as $d) {
            WP_CLI::warning("drift (env ahead, untouched): $d");
        }
        WP_CLI::success(sprintf(
            'applied %d entities (canary %s) — plan was: %s',
            $summary['applied'],
            $summary['canary'],
            json_encode($summary['plan'])
        ));
    }

    /**
     * List structural-ref orphans in one declared authored snapshot table,
     * or repair one listed scalar row through Duo's typed mutation path.
     *
     * ## OPTIONS
     * <table> : Declared authored_snapshot table name.
     * --repo=<path> : Site repo root (contains site.duo.json).
     * [--row=<local-id>] : Exact listed scalar row to mutate.
     * [--delete] : Delete the selected row with declared cascades/invalidation.
     * [--reparent=<column>=<target-local-id>] : Point one declared ref at a managed target.
     * [--format=<format>] : Output format. Accepts json.
     */
    public function orphans($args, $assoc) {
        try {
            // The positional table selector is a gate like any other: it
            // refuses inside the boundary so a --format=json caller reads the
            // same versioned record for it as for a repair-argument gate the
            // backend raises a few lines later.
            $table = $args[0] ?? throw CommandRefusalException::invalidArgument('orphans', '<table>');
            $summary = Orphans::run(
                $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('orphans', '--repo'),
                (string) $table,
                [
                    'row' => $assoc['row'] ?? '',
                    'delete' => isset($assoc['delete']),
                    'reparent' => $assoc['reparent'] ?? '',
                ]
            );
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'orphans');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['rows'] as $row) {
            $identity = implode(',', array_map(
                fn($k, $v) => "$k=$v",
                array_keys($row['identity']),
                array_values($row['identity'])
            ));
            $refs = implode(', ', array_map(
                fn($ref) => "{$ref['column']}={$ref['kind']}:{$ref['local_id']}",
                $row['orphan_refs']
            ));
            WP_CLI::line("ORPHAN $table.$identity ($refs)");
        }
        if ($summary['action'] !== null) {
            WP_CLI::success("resolved {$summary['action']} (canary {$summary['canary']})");
        } elseif (!$summary['rows']) {
            WP_CLI::success("no structural-ref orphans in $table");
        }
    }

    /**
     * Recapture and compare one compiled revision from a fresh WordPress
     * process. Internal half of apply's post-mutation convergence gate.
     *
     * ## OPTIONS
     * --repo=<path>
     * --expected-artifact=<sha256>
     * --compiled=<path>
     * --policy-snapshot=<path>
     * [--with-deletes]
     * [--force-unresolved-refs]
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand verify-canonical
     */
    public function verify_canonical($args, $assoc) {
        try {
            // These four gates already sat inside the try, but WP_CLI::error()
            // exits the process where it stands, so the catch below never saw
            // them and apply's own internal verifier — a pure machine caller —
            // got human stderr.  Typed refusals reach the same formatter as
            // every convergence gate they precede, in the same order.
            $summary = Apply::verify_canonical(
                $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('verify-canonical', '--repo'),
                [
                    'expected_artifact' => $assoc['expected-artifact'] ?? throw CommandRefusalException::invalidArgument('verify-canonical', '--expected-artifact'),
                    'compiled' => $assoc['compiled'] ?? throw CommandRefusalException::invalidArgument('verify-canonical', '--compiled'),
                    'policy_snapshot' => $assoc['policy-snapshot'] ?? throw CommandRefusalException::invalidArgument('verify-canonical', '--policy-snapshot'),
                    'with_deletes' => isset($assoc['with-deletes']),
                    'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                ]
            );
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'verify-canonical');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success(sprintf(
            'canonical verification passed (%d live entities, %d deletions)',
            $summary['live_entities'],
            $summary['deletions']
        ));
    }

    /**
     * Reconcile this environment's active_plugins/template/stylesheet to
     * what state/options/core.json declares — the ONLY place
     * activate_plugin()/deactivate_plugins()/switch_theme() run, and
     * deliberately OUTSIDE `wp duo apply`'s hook-free canary
     * (docs/proposals/code-half.md §3.4): activation hooks MUST fire here
     * (that's how plugins do one-time setup/migrations); apply's canary
     * requires the opposite, so the two can never share a transaction.
     * Refuses loudly, listing every finding, while any currently-desired-
     * active plugin/theme is missing from this environment's code, or a
     * version_range-pinned plugin is outside its declared range —
     * --force-code-mismatch overrides (matching apply's --force-theirs/
     * --force-delete-referenced convention). Idempotent: run again with
     * nothing to reconcile and zero WP APIs get called.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--force-code-mismatch] : proceed despite missing_in_code / outside_version_range findings.
     * [--force-code-drift] : proceed despite code_drift findings (DUO-3231) — installed plugin/theme versions
     *   changed outside 'duo deploy'/'duo capture' since the last recorded baseline.
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--promotion-owner=<token>] : Internal orchestrator lease token shared with apply.
     * [--artifact-hash=<sha256>] : Internal host-observed artifact hash; required with orchestrated promotion-owner.
     * [--materializing-code] : Internal orchestrator flag; prove code-stage completed for this artifact.
     * [--promotion-hold] : Internal orchestrator flag; retain the lease for the following apply phase.
     * [--state-handoff] : Internal promote-only flag; bind lifecycle pre/post state hashes for apply.
     * [--lifecycle-phase=<phase>] : Internal host phase. Accepts retire or activate.
     * [--force-unresolved-refs] : Promotion passthrough for lifecycle handoff snapshots.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function deploy($args, $assoc) {
        try {
            $summary = Deploy::run($assoc['repo'] ?? throw CommandRefusalException::invalidArgument('deploy', '--repo'), [
                'force_code_mismatch' => isset($assoc['force-code-mismatch']),
                'force_code_drift' => isset($assoc['force-code-drift']),
                'compiled' => $assoc['compiled'] ?? '',
                'promotion_owner' => $assoc['promotion-owner'] ?? '',
                'artifact_hash' => $assoc['artifact-hash'] ?? '',
                'materializing_code' => isset($assoc['materializing-code']),
                'promotion_hold' => isset($assoc['promotion-hold']),
                'state_handoff' => isset($assoc['state-handoff']),
                'lifecycle_phase' => $assoc['lifecycle-phase'] ?? 'all',
                'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'deploy');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        foreach ($summary['activated'] as $p) {
            WP_CLI::line("activated: $p");
        }
        foreach ($summary['deactivated'] as $p) {
            WP_CLI::line("deactivated: $p");
        }
        if ($summary['theme_switched'] !== null) {
            WP_CLI::line("theme switched: {$summary['theme_switched']}");
        }
        if ($summary['active_plugins_order_corrected']) {
            WP_CLI::line('active plugin load order corrected');
        }
        WP_CLI::success(sprintf(
            '%d activated, %d deactivated%s%s',
            count($summary['activated']),
            count($summary['deactivated']),
            $summary['theme_switched'] !== null ? ", theme -> {$summary['theme_switched']}" : '',
            $summary['active_plugins_order_corrected'] ? ', active plugin order exact' : ''
        ));
    }

    /**
     * Aggregate the provenance journal and score proposals against manifests.
     *
     * ## OPTIONS
     * [--manifests=<names>] : comma-separated, default "core".
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand journal-report
     */
    public function journal_report($args, $assoc) {
        $names = array_filter(explode(',', $assoc['manifests'] ?? 'core'));
        try {
            $report = Journal::report($names);
        } catch (\Throwable $t) {
            // This command has no required argument, so its only JSON-mode
            // refusals are ledger and manifest-resolution blockers — exactly
            // the ones a machine caller needs a reason code for.
            self::halt_json_failure($t, $assoc, 'journal-report');
            WP_CLI::error($t->getMessage());
        }
        // wp-cli rewrites a bare --json into $assoc['format']='json' before
        // this method ever sees it (verified empirically: no 'json' key is
        // ever present) — check both so it's correct regardless of wp-cli
        // version/convention.
        if (isset($assoc['json']) || ($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($report['rows'] as $r) {
            WP_CLI::line(sprintf(
                '%-24s %-36s %-7s %-15s n=%-4d proposed=%-8s manifest=%-10s %s',
                $r['table'], $r['item'] !== '' ? $r['item'] : '—', $r['surface'],
                $r['caps'] !== '' ? $r['caps'] : 'anon', $r['n'], $r['proposal'], $r['manifest'], $r['verdict']
            ));
        }
        WP_CLI::line('');
        WP_CLI::success(sprintf(
            'agreement on manifest-classified writes: %s%% (agree %d / disagree %d), abstained %d, unclassified (the review queue) %d',
            $report['agreement_pct'] ?? 'n/a',
            $report['agree'], $report['disagree'], $report['abstain'], $report['unclassified']
        ));
    }

    /**
     * Agent + spec version — the stable probe for external tooling
     * (orchestrators check this instead of internal class names).
     */
    public function version($args, $assoc) {
        WP_CLI::line(json_encode([
            'agent' => DUO_AGENT_VERSION,
            'spec_version' => DUO_SPEC_VERSION,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Truncate the provenance journal.
     *
     * @subcommand journal-reset
     */
    public function journal_reset($args, $assoc) {
        global $wpdb;
        Ledger::ensure();
        Db::query("TRUNCATE TABLE {$wpdb->prefix}duo_journal", 'journal truncate');
        WP_CLI::success('journal truncated');
    }

    /**
     * The core loop's review queue (DESIGN.md 3.1.5): unclassified post_meta
     * /term_meta on in-scope entities, representable authored user_meta,
     * plus registered/adapter-declared
     * entity types with live rows but no scope disposition (the same gates
     * `duo capture` aborts on), plus journal-observed unclassified options (options are
     * whitelist-only at capture, so an unlisted option is only visible via
     * the journal). Each item carries whatever evidence exists — entity
     * counts, journal surfaces/caps/proposal, a post/term ref-hint, a
     * secret flag — never a guessed classification.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function pending($args, $assoc) {
        try {
            $items = Pending::scan($assoc['repo'] ?? throw CommandRefusalException::invalidArgument('pending', '--repo'));
        } catch (\Throwable $t) {
            // Failure only. The success record this command emits below is a
            // published contract other tooling parses (sandbox/conformance
            // greps it), and nothing here touches it.
            self::halt_json_failure($t, $assoc, 'pending');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($items, JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$items) {
            WP_CLI::success('no pending unclassified state');
            return;
        }
        foreach ($items as $it) {
            $ev = $it['evidence'];
            $evParts = [];
            if (isset($ev['entities'])) {
                $evParts[] = "entities={$ev['entities']}";
            }
            if (!empty($ev['post_types'])) {
                $evParts[] = 'types=' . implode(',', $ev['post_types']);
            }
            if (!empty($ev['taxonomies'])) {
                $evParts[] = 'taxonomies=' . implode(',', $ev['taxonomies']);
            }
            if (!empty($ev['users'])) {
                $evParts[] = 'users=' . implode(',', $ev['users']);
            }
            if (!empty($ev['owner_candidates'])) {
                $evParts[] = 'owner=' . implode(',', $ev['owner_candidates']);
            }
            if (!empty($ev['value_shapes'])) {
                $evParts[] = 'shapes=' . implode(',', $ev['value_shapes']);
            }
            if (!empty($ev['reason'])) {
                $evParts[] = 'blocked=' . $ev['reason'];
            }
            if (isset($ev['journal'])) {
                $j = $ev['journal'];
                $surf = implode(',', array_map(fn($k, $v) => "$k=$v", array_keys($j['surfaces']), $j['surfaces']));
                $evParts[] = "journal.n={$j['n']}($surf)";
            }
            $hint = '';
            if (isset($it['ref_hint'])) {
                $h = $it['ref_hint'];
                $hint = "{$h['kind']}:{$h['id']} \"{$h['title']}\" ({$h['post_type']})";
            }
            WP_CLI::line(sprintf(
                '%-10s %-32s proposal=%-9s %-50s %-45s %s',
                $it['section'], $it['key'], $it['proposal'] ?? '—',
                implode(' ', $evParts), $hint, $it['secret'] ?? ''
            ));
        }
        WP_CLI::line('');
        WP_CLI::success(count($items) . " pending item(s) — classify with: wp duo classify --repo=<repo> --set 'section:key=class'");
    }

    /**
     * DUO-3290: names and counts what this site actually has versus what
     * Duo can see (options, custom tables) — deliberately NOT the
     * loud-and-blocking gate `pending` already is. Never throws on finding
     * gaps, never affects capture/plan/apply, purely additive visibility.
     * See Coverage.php's own class docblock for the full reasoning.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--format=<format>] : Output format. Accepts json (machine-readable,
     *                        versioned via the report's own top-level
     *                        "format" field — this is the shape the H2
     *                        adoption guide will cite verbatim, so treat it
     *                        as contract-adjacent, not incidental).
     */
    public function coverage($args, $assoc) {
        try {
            $report = Coverage::report($assoc['repo'] ?? throw CommandRefusalException::invalidArgument('coverage', '--repo'));
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'coverage');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }

        $o = $report['options'];
        WP_CLI::line('OPTIONS');
        WP_CLI::line(sprintf(
            '  total=%d  captured=%d  pending=%d  invisible=%d (transient=%d, other=%d)',
            $o['total'], $o['captured'], $o['pending'], $o['invisible_total'],
            $o['invisible_transient'], $o['invisible_other']
        ));
        if ($o['invisible_groups']) {
            $groups = $o['invisible_groups'];
            $large = count($groups) > Coverage::LARGE_LISTING_THRESHOLD;
            if ($large) {
                WP_CLI::warning(sprintf(
                    '%d distinct invisible-option groups — showing the top %d by row count; the count above is exact regardless. Use --format=json for the full listing.',
                    count($groups), Coverage::LARGE_LISTING_THRESHOLD
                ));
                $groups = array_slice($groups, 0, Coverage::LARGE_LISTING_THRESHOLD);
            }
            WP_CLI::line('  invisible groups (prefix, row count, probable owner):');
            foreach ($groups as $g) {
                WP_CLI::line(sprintf(
                    '    %-30s %6d  %s',
                    $g['prefix'], $g['count'], $g['probable_owner'] ?? '(unattributed)'
                ));
            }
        }

        $t = $report['tables'];
        WP_CLI::line('');
        WP_CLI::line('TABLES');
        WP_CLI::line(sprintf(
            '  live=%d  core=%d  declared=%d  undeclared=%d',
            $t['live_total'], $t['core_total'], $t['declared_total'], $t['undeclared_total']
        ));
        if ($t['undeclared']) {
            $undeclared = $t['undeclared'];
            $large = count($undeclared) > Coverage::LARGE_LISTING_THRESHOLD;
            if ($large) {
                WP_CLI::warning(sprintf(
                    '%d undeclared tables — showing the first %d; the count above is exact regardless. Use --format=json for the full listing.',
                    count($undeclared), Coverage::LARGE_LISTING_THRESHOLD
                ));
                $undeclared = array_slice($undeclared, 0, Coverage::LARGE_LISTING_THRESHOLD);
            }
            WP_CLI::line('  undeclared tables (name, row count, probable owner):');
            foreach ($undeclared as $u) {
                WP_CLI::line(sprintf(
                    '    %-40s %8d  %s',
                    $u['table'], $u['row_count'], $u['probable_owner'] ?? '(unattributed)'
                ));
            }
        }
        WP_CLI::line('');
        WP_CLI::success('coverage report complete — this never blocks capture/plan/apply; run `wp duo pending` for the loud, blocking queue.');
    }

    /**
     * DUO-3344: resolve a bounded scope from explicit roots and show what it
     * would carry — the requested roots, everything pulled in by a declared
     * dependency edge (each row naming the edge responsible), and how much
     * unrelated state is left out.
     *
     * Read-only and offline by construction: it compiles the repository
     * revision and walks the same declared edges the compiler validates, so
     * it neither reads nor mutates this environment. Scoped capture,
     * promote, delete, and rollback are deliberately NOT part of this
     * command; resolving a scope is the shared, side-effect-free half those
     * later operations will quote.
     *
     * Two things this preview will not do quietly. A root selector that does
     * not resolve is refused rather than dropped, because a silently empty
     * scope is indistinguishable from a correctly small one. And a reference
     * pointing INTO the scope from outside is reported but never pulled in:
     * it is not a dependency of the scope, it is what a later scoped delete
     * would strand.
     *
     * Note this is entity selection, unrelated to `policy.scope` in
     * site.duo.json — that classifies whole post types and taxonomies as
     * authored/runtime/derived/env and is site-local policy. This command
     * selects individual entities inside whatever policy already admits.
     *
     * ## OPTIONS
     * --repo=<path>
     * --roots=<selectors> : Comma-separated root selectors. One flag only —
     *   wp-cli keeps the LAST occurrence of a repeated flag rather than
     *   accumulating (see `classify`'s docblock for the same quirk). Forms:
     *   post:<uuid>, term:<uuid>, table:<table>:<uuid>, menu:<slug>,
     *   sidebar:<id>, user-meta:<login>, options,
     *   path:<state-relative-path>, or `all` for the whole revision — a
     *   full-site operation is this same model with a wider root set, not a
     *   separate code path.
     * [--format=<format>] : Output format. Accepts json (machine-readable,
     *                        versioned by the report's own "format" field).
     * [--contract] : Emit immutable `duo-scope-contract/v1` read-only
     *                evidence instead of the legacy preview. Contract mode
     *                requires the isolated DUO control-plane bootstrap; use
     *                `duo scope <env> --roots=... --contract` so plugins,
     *                themes, and ordinary MU code cannot run first.
     */
    public function scope($args, $assoc) {
        $contractMode = array_key_exists('contract', $assoc) && $assoc['contract'] !== false;
        try {
            // Both selector gates belong INSIDE the structured boundary: a
            // machine caller asking for JSON gets the same versioned refusal
            // for an absent selector as for any later gate.  The operator
            // prose each one carries is unchanged, including the --roots=all
            // hint, because human mode still prints the private message.
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('scope', '--repo');
            $roots = $assoc['roots'] ?? throw new CommandRefusalException(
                'invalid_arguments',
                '--roots is required for scope',
                'supply --roots (or --roots=all for the whole revision) and rerun scope',
                [],
                '--roots required (or --roots=all for the whole revision)'
            );
            if ($contractMode && (!defined('DUO_CONTROL_PLANE') || DUO_CONTROL_PLANE !== true)) {
                throw new \RuntimeException(
                    'duo: scope --contract requires the isolated DUO control-plane; run `duo scope <env> --roots=... --contract`'
                );
            }
            $policy = Policy::load($repo);
            $compiled = RepositoryCompiler::compile($repo, $policy);
            if ($contractMode) {
                $contract = ScopeContract::resolve($compiled, $policy, explode(',', (string) $roots));
            } else {
                $report = ScopeClosure::resolve($compiled, $policy, explode(',', (string) $roots));
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'scope');
            WP_CLI::error($t->getMessage());
        }
        if ($contractMode) {
            // Contract bytes are canonical because scope_hash is defined over
            // Canon::encode() without that field. Unlike the legacy preview,
            // --contract is always machine evidence, even without --format.
            WP_CLI::line(rtrim(Canon::encode($contract), "\n"));
            return;
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }

        $t = $report['totals'];
        WP_CLI::line(sprintf(
            'scope: %d roots, %d entities in scope (%d closed over), %d excluded, %d media, %d inbound',
            $t['roots'], $t['included'], $t['closure'], $t['excluded'], $t['media'], $t['inbound']
        ));
        WP_CLI::line('');
        WP_CLI::line('ROOTS (' . count($report['roots']) . ') — requested explicitly');
        foreach (self::scope_listing($report['roots'], 'roots') as $row) {
            WP_CLI::line(sprintf('  - %-12s %s  [%s]', $row['type'], $row['path'], $row['selector']));
        }

        $closure = array_values(array_filter(
            $report['included'],
            static fn(array $r): bool => $r['reason'] !== 'root'
        ));
        WP_CLI::line('');
        WP_CLI::line('INCLUDED BY CLOSURE (' . count($closure) . ') — each row names the declared edge that pulled it in');
        foreach (self::scope_listing($closure, 'closure inclusions') as $row) {
            WP_CLI::line(sprintf(
                '  - %-12s %s  <- %s %s of %s',
                $row['type'], $row['path'], $row['reason'], $row['locator'], $row['from_path']
            ));
        }

        if ($report['media']) {
            WP_CLI::line('');
            WP_CLI::line('MEDIA (' . count($report['media']) . ') — blobs owned by included attachments');
            foreach ($report['media'] as $blob) {
                WP_CLI::line('  - ' . $blob);
            }
        }

        if ($report['inbound']) {
            WP_CLI::line('');
            WP_CLI::line('INBOUND REFERENCES (' . count($report['inbound']) . ') — outside the scope, pointing into it; NOT included');
            foreach (self::scope_listing($report['inbound'], 'inbound references') as $row) {
                WP_CLI::line(sprintf('  - %s %s -> %s', $row['path'], $row['locator'], $row['target_path']));
            }
            WP_CLI::line('  these are unaffected by a scoped write, and are what a scoped delete would strand');
        }

        $excludedParts = [];
        foreach ($report['excluded']['by_type'] as $type => $count) {
            $excludedParts[] = "$type $count";
        }
        WP_CLI::line('');
        WP_CLI::line(sprintf(
            'EXCLUDED (%d)%s',
            $report['excluded']['total'],
            $excludedParts ? ' — ' . implode(', ', $excludedParts) : ' — this scope is the whole revision'
        ));
        WP_CLI::line('');
        WP_CLI::success('scope resolved — this is a read-only preview; it captures, promotes, and deletes nothing.');
    }

    /**
     * A resolved scope can legitimately be the whole site (--roots=all), so
     * the human renderer truncates its listings while the counts beside them
     * stay exact — same bargain `coverage` already strikes with its own
     * large listings. JSON is never truncated.
     *
     * Deliberately BELOW scope(): a private helper between a command and its
     * docblock silently orphans the wp-cli synopsis, leaving `wp duo scope
     * --help` empty and the declared options unvalidated.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function scope_listing(array $rows, string $noun): array {
        if (count($rows) <= Coverage::LARGE_LISTING_THRESHOLD) {
            return $rows;
        }
        WP_CLI::warning(sprintf(
            '%d %s — showing the first %d; the count above is exact regardless. Use --format=json for the full listing.',
            count($rows), $noun, Coverage::LARGE_LISTING_THRESHOLD
        ));
        return array_slice($rows, 0, Coverage::LARGE_LISTING_THRESHOLD);
    }

    /**
     * Write policy classification rules — the `wp duo pending` -> `wp duo
     * classify` step of the core loop. Rules land in site.duo.json's policy
     * overrides (Policy::set_rule); this command does not itself capture.
     *
     * ## OPTIONS
     * --repo=<path>
     * --set=<spec>         : "section:key=class[,ref=post][,cast=string]"
     *   (section is options|post_meta|term_meta|user_meta|scope; scope keys are
     *   post_type:<name> or taxonomy:<name>, and accept only
     *   authored|runtime|derived|env. The spec is split on the
     *   FIRST ':' and the FIRST '='). Two wp-cli parsing quirks verified
     *   empirically against this exact command (both silently swallow the
     *   value otherwise — instrumented with a live var_dump of $args/$assoc,
     *   not assumed):
     *     1. Use the `=` form (--set=foo:bar=baz). The space form
     *        (--set foo:bar=baz) is NOT equivalent — wp-cli parses a
     *        space-separated value as a bare boolean flag ($assoc['set']
     *        becomes `true`) and the intended value lands in positional
     *        $args instead, silently.
     *     2. Repeating the flag (--set=a --set=b) does NOT accumulate: only
     *        the LAST occurrence survives ($assoc['set'] is a plain string,
     *        never an array, in this wp-cli version). So pass multiple
     *        rules as ONE --set value, semicolon-separated:
     *          --set='post_meta:foo=runtime;options:bar=authored,ref=post'
     * [--allow-secret]     : permit class=authored when the key's current
     *                         value hard-matches a secret pattern; sets
     *                         allow_secret:true on the rule written (the
     *                         same escape hatch Capture's guard honors).
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function classify($args, $assoc) {
        $written = [];
        try {
            // Both selectors refuse inside the boundary. --set carries an
            // example in its operator prose, which stays byte-identical; the
            // machine record names the flag and repeats the example in
            // remediation, where a caller can read it as guidance rather than
            // having to scrape it out of stderr.
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('classify', '--repo');
            $raw = $assoc['set'] ?? throw new CommandRefusalException(
                'invalid_arguments',
                '--set is required for classify',
                'supply --set "section:key=class" (for example --set "post_meta:foo=runtime") and rerun classify',
                [],
                '--set required, e.g. --set "post_meta:foo=runtime"'
            );
            $specs = [];
            foreach ((array) $raw as $chunk) {
                foreach (explode(';', (string) $chunk) as $one) {
                    $one = trim($one);
                    if ($one !== '') {
                        $specs[] = $one;
                    }
                }
            }
            $allowSecret = isset($assoc['allow-secret']);
            // A refusal partway through a multi-spec --set leaves the earlier
            // specs already written to site.duo.json, exactly as before: this
            // is one record about why the run stopped, not a rollback claim.
            foreach ($specs as $spec) {
                $written[] = self::parse_and_write_classify_spec($repo, $spec, $allowSecret);
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'classify');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($written, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($written as $w) {
            $extra = [];
            if (isset($w['rule']['ref'])) {
                $extra[] = "ref={$w['rule']['ref']}";
            }
            if (isset($w['rule']['cast'])) {
                $extra[] = "cast={$w['rule']['cast']}";
            }
            if (!empty($w['rule']['allow_secret'])) {
                $extra[] = 'allow_secret=true';
            }
            WP_CLI::line("set {$w['section']}:{$w['key']} = {$w['rule']['class']}" . ($extra ? ' (' . implode(', ', $extra) . ')' : ''));
        }
        WP_CLI::success(count($written) . " rule(s) written to site.duo.json — run: wp duo capture --repo=$repo");
    }

    /**
     * Parses one "section:key=class[,ref=x][,cast=y]" spec — split on the
     * FIRST ':' and FIRST '=' — and writes it via Policy::set_rule,
     * refusing class=authored over a hard-matched secret unless $allowSecret.
     */
    private static function parse_and_write_classify_spec(string $repo, string $spec, bool $allowSecret): array {
        $colon = strpos($spec, ':');
        $eq = strpos($spec, '=');
        if ($colon === false || $eq === false || $eq < $colon) {
            throw new \RuntimeException("duo: bad --set spec '$spec' (expected section:key=class[,ref=..][,cast=..])");
        }
        $section = substr($spec, 0, $colon);
        $key = substr($spec, $colon + 1, $eq - $colon - 1);
        $tail = substr($spec, $eq + 1);

        $parts = explode(',', $tail);
        $class = array_shift($parts);
        $rule = ['class' => $class];
        foreach ($parts as $p) {
            $kv = explode('=', $p, 2);
            $k = $kv[0] ?? '';
            $v = $kv[1] ?? '';
            if ($k === 'ref') {
                $rule['ref'] = $v;
            } elseif ($k === 'cast') {
                $rule['cast'] = $v;
            } else {
                throw new \RuntimeException("duo: unknown option '$k' in --set spec '$spec' (expected ref=|cast=)");
            }
        }

        if ($class === 'authored') {
            $current = Pending::current_value($section, $key);
            if (is_string($current)) {
                $label = Secrets::hard_match($current);
                if ($label !== null) {
                    if (!$allowSecret) {
                        throw new \RuntimeException(
                            "duo: refusing '$spec' — current value of $section:$key looks like a $label; pass --allow-secret to override"
                        );
                    }
                    $rule['allow_secret'] = true;
                }
            }
        }

        Policy::set_rule($repo, $section, $key, $rule);
        return ['section' => $section, 'key' => $key, 'rule' => $rule];
    }

    /**
     * The generalized suspicious-ref linter (task #11; docs/frontier/{fse,
     * polylang,elementor}.md): scans a CAPTURED state tree for ref-shaped
     * values that reached canonical state WITHOUT ever passing through a
     * declared rewrite path. This is the correctness gate byte-identical
     * round-tripping cannot be: a value the tokenizer never looks at gets
     * captured and re-applied as the exact same wrong bytes on every
     * environment, so `duo capture`'s own determinism check reports
     * "clean" on real corruption — all three frontier explorations
     * independently hit this blind spot and lost real content to it.
     *
     * Four detection classes, each finding tagged accordingly:
     *   bare_id             — a numeric post_meta/option value with no ref
     *                         declared in its rule, where the number
     *                         happens to match an existing post/term id on
     *                         THIS environment (finding #9's original ask).
     *   escaped_home        — this environment's home URL in JSON-escaped
     *                         form (`https:\/\/…`), in a post body or any
     *                         meta/option value — tokenize_text() only
     *                         matches the plain, unescaped form (Elementor's
     *                         _elementor_data shape).
     *   unregistered_block_attr — id/ids/ref(-suffixed) block attributes
     *                         with no block_attrs registry rule for that
     *                         exact path (FSE's core/navigation-link shape
     *                         before it had one), plus plain-form home URLs
     *                         sitting in ANY string block attribute (block
     *                         attrs are never routed through the text
     *                         tokenizer, rule or no rule).
     *   serialized_desc_ids — a term description that unserializes (PHP
     *                         serialize format) to data containing an
     *                         integer matching an existing post/term id
     *                         (Polylang's post_translations/
     *                         term_translations shape).
     *
     * Every finding is a plan-time SIGNAL, not proof of corruption: small
     * ids legitimately coincide with unrelated authored numbers (counts,
     * versions, ordering indexes) — that caveat travels in each finding's
     * own "note", never left implicit. Exits 1 when findings exist (a gate
     * only when a caller scripts it that way — see sandbox/conformance/
     * run.sh, which currently runs this warn-only, pending main wiring it
     * into capture as a hard gate).
     *
     * ## OPTIONS
     * --repo=<path>
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function lint($args, $assoc) {
        try {
            $repo = $assoc['repo'] ?? throw CommandRefusalException::invalidArgument('lint', '--repo');
            $policy = Policy::load($repo);
            $findings = Lint::scan_tree(rtrim($repo, '/') . '/state', $policy);
        } catch (\Throwable $t) {
            // A refusal and a findings-bearing success both exit 1 here, so a
            // caller scripting this as a gate could not previously tell "the
            // tree is dirty" from "the tree was never scanned". The refusal
            // now carries a reason code; the findings path is untouched.
            self::halt_json_failure($t, $assoc, 'lint');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($findings, JSON_UNESCAPED_SLASHES));
        } elseif (!$findings) {
            WP_CLI::success('no findings — captured state is clean');
        } else {
            foreach ($findings as $f) {
                $val = is_scalar($f['value']) ? (string) $f['value'] : json_encode($f['value'], JSON_UNESCAPED_SLASHES);
                $match = isset($f['matches'])
                    ? sprintf(' matches=%s:%d "%s" (%s)', $f['matches']['kind'], $f['matches']['id'], $f['matches']['title'], $f['matches']['post_type'])
                    : '';
                WP_CLI::line(sprintf('%-24s %-55s %-32s value=%s%s', $f['class'], $f['path'], $f['locator'], $val, $match));
                WP_CLI::line('    ' . $f['note']);
            }
            WP_CLI::line('');
            WP_CLI::warning(count($findings) . ' finding(s) — review before trusting a byte-identical round trip');
        }
        if ($findings) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Draft-manifest export: every site-policy rule (not inherited manifest
     * rules — the human is promoting decisions they made) whose key matches
     * --match, grouped into a manifest-shaped JSON document on stdout.
     * site.duo.json is left untouched; promoting rules into a real manifest
     * file upstream is a deliberate, separate human act.
     *
     * ## OPTIONS
     * --repo=<path>
     * --match=<regex>   : PCRE body (no delimiters), tested against each key.
     * --name=<name>     : the exported manifest's "name" field.
     *
     * @subcommand policy-to-manifest
     */
    public function policy_to_manifest($args, $assoc) {
        // DUO-3399, reviewed and deliberately left human-only: this command
        // and manifest-pin print canonical JSON unconditionally and advertise
        // no --format, so there is no JSON mode to honor and no format the
        // caller negotiated.  halt_json_failure() answers only a caller that
        // asked for JSON; wiring it here would either be dead code or require
        // inventing a --format flag, growing a contract nobody requested.
        // The one-document-on-stdout shape stays unambiguous without it: on
        // success stdout is exactly one JSON value, on refusal stdout is
        // empty and the exit code is non-zero, which a caller can already
        // distinguish.  The envelope's closed set is "every command that
        // advertises --format=json"; these two are outside it by design.
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        $match = $assoc['match'] ?? WP_CLI::error('--match required');
        $name = $assoc['name'] ?? WP_CLI::error('--name required');
        try {
            $manifest = Policy::export_manifest($repo, $match, $name);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        WP_CLI::line(rtrim(Canon::encode($manifest)));
    }

    /**
     * Emit a copy-pasteable content-addressed site.duo.json pin for one
     * installed manifest. A stale declared digest still cannot prevent
     * calculating the reviewed replacement digest: the requested name is
     * passed as an explicit pin, so the repository's own (possibly stale)
     * `manifests` array is never resolved even when --repo is given.
     *
     * --repo does, however, put the whole repository through ordinary policy
     * loading, so an UNRELATED site.duo.json error (a malformed policy
     * override, an invalid code declaration) blocks pin generation with that
     * error. Fix the repository, or omit --repo when pinning a shipped
     * adapter, which needs no repository at all.
     *
     * The emitted pin carries whatever source the name actually resolved
     * from, including DUO-3339's `"plugin"`. There is deliberately no flag to
     * ask for that: pasting the emitted object into site.duo.json IS the
     * deliberate act — the same place bind_explicit_pins() puts deliberateness
     * for a signed site adapter — and a pin naming `plugin` then refuses
     * loudly the day a reviewed definition claims that name instead.
     *
     * ## OPTIONS
     * --name=<name> : Manifest file name without the .json suffix.
     * [--repo=<path>] : Site repository whose `adapters/` source may also supply the manifest.
     *
     * @subcommand manifest-pin
     */
    public function manifest_pin($args, $assoc) {
        // Human-only refusals by the same reviewed decision recorded on
        // policy_to_manifest() above: no --format is advertised, so no
        // machine caller ever asked this command for a JSON contract.
        $name = $assoc['name'] ?? WP_CLI::error('--name required');
        // --repo names the adapter source explicitly (DUO-3314). Without it
        // only the shipped library is searched, exactly as before, so pinning
        // a site-installed adapter is a deliberate act that states where the
        // adapter came from rather than a lookup that silently widens.
        $repo = isset($assoc['repo']) ? (string) $assoc['repo'] : null;
        try {
            $policy = Policy::load($repo, [(string) $name]);
            $resolved = RepositoryCompiler::resolved_adapters($policy);
            $pin = [
                'name' => (string) $name,
                'digest' => (string) ($resolved[0]['digest'] ?? ''),
                'source' => (string) ($resolved[0]['source'] ?? 'shipped'),
            ];
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        WP_CLI::line(rtrim(Canon::encode($pin)));
    }

    /**
     * Every adapter installed ON THIS TARGET, across all three sources, with
     * everything that is wrong with them (DUO-3339).
     *
     * This verb is structurally required rather than a convenience. The host
     * command `duo adapter list|inspect|doctor` runs WordPress-free, so it can
     * never see the third adapter source: a bundled `duo-adapter.json` lives
     * under WP_PLUGIN_DIR, which only the target has. Without this verb the
     * plugin source would be discoverable by nothing, and "never silently
     * omit" is the whole point of the catalog surface.
     *
     * It is deliberately THIN — AdapterSources::survey() is the same one scan
     * discover() runs, so a refusal reported here is the engine's own refusal
     * with its own message, and the host catalog and this verb cannot drift
     * into two descriptions of one rule. Everything it does NOT do is in the
     * emitted `deferred` list, on every run, passing or failing.
     *
     * Exits 1 when anything was surfaced (a refusal, or a grammar verdict that
     * is not `ok`), matching every other finding-reporting verb here.
     * `not_installed` rows deliberately do NOT flip it: a shadowed adapter is
     * the precedence rule working, and a permanently red survey on every site
     * running a plugin that bundles a colliding name would destroy the exit
     * code's meaning.
     *
     * ## OPTIONS
     * [--repo=<path>] : Site repository whose adapters/ source and pins also count.
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand adapter-survey
     */
    public function adapter_survey($args, $assoc) {
        $repo = isset($assoc['repo']) ? (string) $assoc['repo'] : null;
        try {
            $survey = AdapterSources::survey($repo);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        $grammarErrors = 0;
        $grammarUnjudged = 0;
        foreach ($survey['adapters'] as $row) {
            $grammarErrors += $row['grammar']['status'] === AdapterSources::GRAMMAR_ERROR ? 1 : 0;
            $grammarUnjudged += $row['grammar']['status'] === AdapterSources::GRAMMAR_BLOCKED ? 1 : 0;
        }
        $counts = [];
        foreach ([AdapterSources::SHIPPED, AdapterSources::SITE, AdapterSources::PLUGIN] as $source) {
            $counts[$source] = count(array_filter(
                $survey['adapters'],
                static fn(array $r): bool => $r['source'] === $source
            ));
        }
        $document = [
            'format' => 'duo-adapter-catalog/v2',
            'spec_version' => DUO_SPEC_VERSION,
            'command' => 'survey',
            'manifests_dir' => Policy::manifests_dir(),
            'repo' => $repo,
            'sources' => $survey['sources'],
            'adapters' => $survey['adapters'],
            'not_installed' => $survey['not_installed'],
            'refusals' => $survey['refusals'],
            'deferred' => self::adapter_survey_deferred(),
            'summary' => [
                'adapters' => count($survey['adapters']),
                'shipped' => $counts[AdapterSources::SHIPPED],
                'site' => $counts[AdapterSources::SITE],
                'plugin' => $counts[AdapterSources::PLUGIN],
                'not_installed' => count($survey['not_installed']),
                'grammar_error' => $grammarErrors,
                'grammar_unjudged' => $grammarUnjudged,
                'refusals' => count($survey['refusals']),
            ],
        ];
        $findings = $survey['refusals'] !== [] || $grammarErrors > 0 || $grammarUnjudged > 0;
        $document['status'] = $findings ? 'error' : 'ok';

        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($document, JSON_UNESCAPED_SLASHES));
            if ($findings) {
                WP_CLI::halt(1);
            }
            return;
        }

        WP_CLI::line('manifests dir: ' . $document['manifests_dir']);
        WP_CLI::line('site repo:     ' . ($repo ?? '(none)'));
        WP_CLI::line('');
        WP_CLI::line('adapter sources (three exist; this run reached the ones marked scanned):');
        foreach ($survey['sources'] as $source) {
            WP_CLI::line(sprintf(
                '  [%s] %-8s %s',
                empty($source['scanned']) ? 'not scanned' : '  scanned  ',
                (string) $source['source'],
                (string) ($source['path'] ?? '(none)')
            ));
            WP_CLI::line('          ' . (string) ($source['note'] ?? ''));
        }
        WP_CLI::line('');
        WP_CLI::line('installed adapters (each grammar verdict is an ISOLATED load; see deferred):');
        foreach ($survey['adapters'] as $row) {
            WP_CLI::line(sprintf(
                '  [%s] %-28s %-8s %-21s %-13s %s',
                $row['grammar']['status'],
                $row['name'],
                $row['source'],
                $row['trust_tier'],
                $row['certification'] ?? 'no-registry',
                $row['path']
            ));
            WP_CLI::line('          tier basis: ' . $row['tier_basis']);
            if ($row['grammar']['message'] !== null) {
                WP_CLI::line('          ' . $row['grammar']['message']);
            }
        }
        if ($survey['adapters'] === []) {
            WP_CLI::line('  (none)');
        }
        WP_CLI::line('');
        WP_CLI::line('installed, NOT loaded (reported every run; never the exit code — this is precedence working):');
        foreach ($survey['not_installed'] as $row) {
            $winner = is_array($row['winner'] ?? null) ? $row['winner'] : null;
            WP_CLI::line('  [' . $row['reason_code'] . '] ' . ($row['name'] ?? '(name unreadable)')
                . ' — ' . $row['path']
                . ($winner === null ? '' : ' — ' . $winner['source'] . ' answers to this name ('
                    . $winner['path'] . ')'));
            WP_CLI::line('          ' . $row['message']);
        }
        if ($survey['not_installed'] === []) {
            WP_CLI::line('  (none)');
        }
        WP_CLI::line('');
        WP_CLI::line('refusals — installed files the engine will not load, and why:');
        foreach ($survey['refusals'] as $refusal) {
            WP_CLI::line('  [' . $refusal['code'] . '] [' . ($refusal['source'] ?? '?') . ' source, '
                . ($refusal['scope'] ?? '?') . ' scope] ' . implode(', ', (array) $refusal['paths']));
            WP_CLI::line('          ' . $refusal['message']);
            WP_CLI::line('          remediation: ' . $refusal['remediation']);
        }
        if ($survey['refusals'] === []) {
            WP_CLI::line('  (none)');
        }
        WP_CLI::line('');
        WP_CLI::line('deferred — NOT checked here, and not checked anywhere else by this command:');
        foreach ($document['deferred'] as $row) {
            WP_CLI::line('  [deferred] ' . $row['surface'] . ' — ' . $row['check']);
            WP_CLI::line('             ' . $row['why']);
        }
        $s = $document['summary'];
        WP_CLI::line('');
        WP_CLI::line("summary: {$s['adapters']} adapter(s) installed ({$s['shipped']} shipped, {$s['site']} site, "
            . "{$s['plugin']} plugin), {$s['not_installed']} installed but not loaded, {$s['grammar_error']} "
            . "grammar error(s), {$s['grammar_unjudged']} unjudged, {$s['refusals']} refusal(s); "
            . count($document['deferred']) . ' check(s) NOT performed here');
        if ($findings) {
            WP_CLI::halt(1);
        }
    }

    /**
     * What the survey does not answer, emitted unconditionally for the reason
     * `duo manifest-validate` and `duo adapter doctor` both give: a tool that
     * listed its limits only on failure would let silence read as "everything
     * about these adapters is verified".
     *
     * Shorter than the host catalog's list by exactly one row — this process
     * IS the target, so the plugin source is scanned rather than deferred.
     *
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function adapter_survey_deferred() {
        $rows = [
            [
                'surface' => 'interpreter / post_types[].regen_dependency.regenerator / providers[].source=manifest',
                'check' => 'Policy::interpreters() / Policy::regenerators() / Providers::diagnose()',
                'why' => 'the manifest-shipped PHP these name is REPORTED here (it is what the trust tier is '
                    . 'derived from) and deliberately never loaded: checking that a file defines its contract '
                    . 'class requires running its top level and its constructor, which an inventory of what is '
                    . 'installed must not do',
            ],
            [
                'surface' => 'the pinned SET (cross-manifest guards)',
                'check' => 'Policy::validate_one_owner_per_declared_name() / '
                    . 'validate_no_conflicting_provider_ids() / validate_no_conflicting_adapter_claims()',
                'why' => "each adapter's grammar verdict here is an ISOLATED load, so a manifest can read `ok` "
                    . 'and still be illegal in company — one owner per declared name, globally unique provider '
                    . 'ids, and one plugin/theme range per claim are properties of a SET, which `duo plan` and '
                    . '`duo apply` co-load',
            ],
            [
                'surface' => 'provider negotiation and certification against this environment',
                'check' => 'Providers::negotiate() / CapabilityRegistry::report()',
                'why' => 'whether a declared provider answers, whether its owning plugin is inside its version '
                    . 'window, and whether a capability claim holds for this WordPress/PHP/database are '
                    . 'negotiated and evaluated facts, not declared ones — run `wp duo capabilities --repo=<path>` '
                    . 'and `duo plan <env>` for those',
            ],
        ];
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * Report the external ratification boundary for this repository's exact
     * pinned manifests, or the complete shipped library with --all.
     *
     * ## OPTIONS
     * [--repo=<path>] : Site repository whose manifest pins should be resolved.
     * [--all] : Report every shipped manifest instead of a site repository.
     * [--operation=<operation>] : Capability to evaluate. Defaults to promote.
     * [--surface=<surface>] : Exact registry surface to evaluate.
     * [--revision=<sha>] : Exact evidence-bound platform revision to evaluate.
     * [--format=<format>] : Output format. Accepts json.
     */
    public function capabilities($args, $assoc) {
        $all = isset($assoc['all']);
        $repo = isset($assoc['repo']) ? (string) $assoc['repo'] : null;
        try {
            // Both selector gates move inside the boundary, keeping their
            // original order relative to each other and to the registry reads
            // below. This is the command an external reviewer polls for a
            // ratification verdict, so "which selector did I get wrong" has to
            // be readable from the same JSON the verdict would have arrived in.
            if ($all && $repo !== null) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'capabilities accepts either --all or --repo, not both',
                    'pass exactly one of --all or --repo and rerun capabilities',
                    [],
                    '--all and --repo are mutually exclusive'
                );
            }
            $query = [
                'operation' => isset($assoc['operation']) ? (string) $assoc['operation'] : 'promote',
            ];
            foreach (['surface', 'revision'] as $key) {
                if (isset($assoc[$key])) {
                    $query[$key] = (string) $assoc[$key];
                }
            }
            if ($all) {
                $dir = Policy::manifests_dir();
                $dispositions = ManifestDispositions::load($dir);
                if ($dispositions === null) {
                    throw new \RuntimeException("duo: $dir has no external manifest disposition registry");
                }
                $manifests = [];
                foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
                    if (basename($file) !== 'dispositions.json') {
                        $manifest = Canon::decode(Canon::read_file($file));
                        // DUO-3371: this path loads the library without Policy::load(),
                        // so it must hold the same name==basename rule itself — a
                        // mismatch otherwise surfaces as a malformed registry claim
                        // that never says the name field is wrong.
                        AdapterSources::assert_declared_name($manifest, basename($file, '.json'), AdapterSources::SHIPPED, $file);
                        $manifests[] = $manifest;
                    }
                }
                $registry = CapabilityRegistry::load($dir, $dispositions, $manifests);
                if ($registry === null) {
                    throw new \RuntimeException("duo: $dir has no generated capability registry");
                }
                // DUO-3339: real provenance, not the absent-sources default.
                // Every row here IS shipped, so the source word does not
                // change — but the tier and the file each row came from are
                // now READ from the same scan `--repo` uses instead of being
                // reconstructed by report()'s fallback, so a shipped shim
                // prints its tier and its path in the library view too. Two
                // code paths agreeing today is not the same as one path.
                $report = $registry->report(
                    $manifests,
                    $query,
                    null,
                    AdapterSources::discover($dir, null)->diagnostics($manifests)
                );
            } else {
                if ($repo === null || $repo === '') {
                    throw new CommandRefusalException(
                        'invalid_arguments',
                        '--repo is required for capabilities',
                        'supply --repo (or --all to report every shipped manifest) and rerun capabilities',
                        [],
                        '--repo required unless --all is used'
                    );
                }
                $report = Policy::load($repo, null, true)->capability_report($query);
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc, 'capabilities');
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($report['manifests'] as $row) {
            $verdict = (string) ($row['verdict']['status'] ?? $row['status'] ?? 'unsupported');
            $source = is_array($row['source'] ?? null) ? $row['source'] : [];
            WP_CLI::line('CAPABILITY ' . $row['name'] . ' ' . strtoupper($verdict));
            // Source and trust tier lead the block, before the reviewed claim
            // fields: an operator reading a blocked adapter needs to know
            // whether it is even an adapter this project reviews before any of
            // the certification detail below means anything.
            WP_CLI::line(
                '  source: ' . ($source['source'] ?? 'shipped')
                . (($source['path'] ?? null) !== null ? ' (' . $source['path'] . ')' : '')
            );
            WP_CLI::line('  trust_tier: ' . ($source['trust_tier'] ?? 'unknown'));
            WP_CLI::line('  certification: ' . ($source['certification'] ?? 'registry'));
            WP_CLI::line('  reason: ' . ($row['reason'] ?? 'no reviewed disposition'));
            WP_CLI::line('  supported_versions: ' . trim(Canon::encode($row['supported_versions'])));
            WP_CLI::line('  plugin_execution: ' . trim(Canon::encode($row['plugin_execution'])));
            WP_CLI::line('  authored_state: ' . trim(Canon::encode($row['authored_state'])));
            WP_CLI::line('  adapter_digest: ' . ($row['adapter_digest'] ?? 'none'));
            WP_CLI::line('  evidence_bundle: ' . ($row['evidence']['bundle_digest'] ?? 'none'));
            WP_CLI::line('  operations: ' . implode(', ', $row['operations'] ?? []));
            WP_CLI::line('  surfaces: ' . implode(', ', $row['surfaces'] ?? []));
            foreach ($row['unsupported'] as $unsupported) {
                WP_CLI::line(
                    '  unsupported: ' . $unsupported['surface'] . ' / ' . $unsupported['operation']
                    . ' — ' . $unsupported['reason']
                );
            }
            foreach ($row['verdict']['reasons'] ?? [] as $reason) {
                WP_CLI::line('  blocked: ' . ($reason['code'] ?? 'unknown') . ' — ' . ($reason['message'] ?? ''));
                if (($reason['remediation'] ?? '') !== '') {
                    WP_CLI::line('    remediation: ' . $reason['remediation']);
                }
            }
        }
        foreach ($report['profiles'] as $name => $profile) {
            WP_CLI::line('PROFILE ' . $name . ' ' . strtoupper((string) $profile['status']));
        }
        WP_CLI::line(($report['evidence_scope'] ?? null) === 'per_manifest'
            ? 'evidence: per manifest (see each adapter)'
            : 'evidence bundle: ' . ($report['evidence']['bundle_digest'] ?? 'none'));
        WP_CLI::line('registry sha256: ' . ($report['registry_sha256'] ?? 'none'));
    }
}

WP_CLI::add_command('duo', Cli::class);
