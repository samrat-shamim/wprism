<?php
namespace Duo;

require_once __DIR__ . '/Secrets.php';

/**
 * Deliberately public, machine-readable command refusal.
 *
 * Ordinary Throwable messages are operator-only: they may contain paths,
 * logins, provider details, or secrets and therefore must never be promoted
 * into a JSON API by a catch-all formatter.  A source throws this type only
 * when it can name a stable reason and provide reviewed public guidance.
 */
final class CommandRefusalException extends \RuntimeException {
    /** @var list<array<string,mixed>> */
    public array $diagnostics;
    /** @var list<array<string,mixed>> */
    public array $forcedOverrides;
    public bool $detailsRedacted = false;

    public function __construct(
        public string $reasonCode,
        public string $publicMessage,
        public string $remediation,
        array $diagnostics = [],
        ?string $operatorMessage = null,
        ?\Throwable $previous = null,
        array $forcedOverrides = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('command refusal reason code is invalid');
        }
        if ($publicMessage === '' || $remediation === '') {
            throw new \InvalidArgumentException('command refusal guidance must not be empty');
        }
        $this->diagnostics = array_values($diagnostics);
        $this->forcedOverrides = array_values($forcedOverrides);
        if (self::containsSensitivePublicDetail([
            'message' => $this->publicMessage,
            'remediation' => $this->remediation,
            'diagnostics' => $this->diagnostics,
            'forced_overrides' => $this->forcedOverrides,
        ])) {
            $this->publicMessage = 'structured refusal details were redacted';
            $this->remediation = 'inspect private operator evidence and recovery state before another attempt';
            $this->diagnostics = [];
            $this->forcedOverrides = [];
            $this->detailsRedacted = true;
        }
        // Human mode keeps the rich operator-only evidence.  JSON mode reads
        // only the reviewed public fields above.
        parent::__construct($operatorMessage ?? $publicMessage, 0, $previous);
    }

    public static function invalidArgument(string $command, string $argument): self {
        return new self(
            'invalid_arguments',
            "$argument is required for $command",
            "supply $argument and rerun $command",
            [],
            "$argument required"
        );
    }

    /**
     * Reviewed public boundary for a known apply precondition.
     *
     * Callers must supply only constant, value-free guidance. Runtime target,
     * provider, entity, path, and exception detail remains operator-only in
     * the exception chain and is never copied into the JSON payload.
     */
    public static function applyRefused(
        string $publicMessage,
        string $remediation,
        ?string $operatorMessage = null,
        ?\Throwable $previous = null
    ): self {
        return new self(
            'apply_refused',
            $publicMessage,
            $remediation,
            [],
            $operatorMessage,
            $previous
        );
    }

    /**
     * A scoped target read found selected ledger identities which are not
     * backed by the same strict canonical observation. Keep the public
     * contract value-free: exact UUIDs, local ids, tables, and target rows
     * stay private while the operator gets the existing repair route.
     */
    public static function scopedIdentityRecoveryRequired(?\Throwable $previous = null): self {
        return new self(
            'scoped_identity_recovery_required',
            'scoped target observation found selected identity mappings that are stale, missing, or inconsistent; bounded mutation was refused',
            'run the existing capture or identity-recovery gate, rebuild the scoped plan, and reconcile any retained scoped session before retrying',
            [],
            'duo: scoped target selected ledger identities are not backed by the exact strict target observation',
            $previous
        );
    }

    /** Stable refusal when full plan/apply finds retained but unbacked identity. */
    public static function canonicalIdentityRecoveryRequired(?\Throwable $previous = null): self {
        return new self(
            'canonical_identity_recovery_required',
            'canonical mapped identity has no matching live backing row; plan/apply was refused before creating or rebinding it',
            'restore the database-matched backup or capture the intended deletion before retrying plan/apply',
            [],
            'duo: canonical mapped identity has no matching live backing row; refusing to create or rebind it. Restore the database-matched backup or capture the intended deletion before plan/apply.',
            $previous
        );
    }

    /** Stable public boundary for explain's deliberately non-repairing read. */
    public static function explainObservationPrecondition(\Throwable $previous): self {
        return new self(
            'explain_observation_precondition_failed',
            'explain requires internally-consistent target state that is locally observable without repair or provider execution',
            'run the existing capture, identity-recovery, or attachment-materialization gate, then rerun plan and explain',
            [[
                'code' => 'explain_observation_precondition_failed',
                'message' => 'strict observation found target state that requires repair or external attachment materialization',
                'remediation' => 'repair or materialize through capture; explain will not mutate identity state or invoke providers itself',
            ]],
            'duo: explain strict observation found identity, ledger, or attachment state that requires the existing capture/provider gate',
            $previous
        );
    }

    /** One public contract for every filesystem or database recovery ambiguity. */
    public static function ambiguousCaptureRecovery(
        string $operatorMessage,
        ?\Throwable $previous = null
    ): self {
        return new self(
            'capture_recovery_ambiguous',
            'capture recovery found an ambiguous publication or commit boundary',
            'do not retry or discard the retained backup; inspect the durable intent, receipt, and database commit proof before continuing',
            [[
                'code' => 'capture_recovery_ambiguous',
                'message' => 'durable recovery evidence does not authorize an automatic choice',
                'remediation' => 'preserve the intent, receipt, and retained backup and reconcile the recorded publication manually',
            ]],
            $operatorMessage,
            $previous
        );
    }

    /** @return array<string,mixed> */
    public function payload(): array {
        $payload = [
            'error' => $this->reasonCode,
            'message' => $this->publicMessage,
            'remediation' => $this->remediation,
        ];
        if ($this->diagnostics !== []) {
            $payload['diagnostics'] = $this->diagnostics;
        }
        if ($this->forcedOverrides !== []) {
            $payload['forced_overrides'] = $this->forcedOverrides;
        }
        if ($this->detailsRedacted) {
            $payload['details_redacted'] = true;
        }
        return $payload;
    }

    /**
     * Shared last-line guard for every deliberately public refusal payload.
     * Legacy compiler/authorization exception objects predate this class, so
     * Cli uses the same predicate before preserving their diagnostics.
     */
    public static function containsSensitivePublicDetail(mixed $value): bool {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((is_string($key) && self::containsSensitivePublicDetail($key))
                    || self::containsSensitivePublicDetail($item)) {
                    return true;
                }
            }
            return false;
        }
        // Refusal diagnostics are a closed data object, not an extension
        // point for JsonSerializable or resource-backed values. Their
        // serialization can expose bytes not visible through an array walk,
        // so fail closed and let the caller retain only the stable code.
        if (is_object($value) || is_resource($value)) {
            return true;
        }
        if (!is_string($value) || $value === '') {
            return false;
        }
        if (Secrets::hard_match($value) !== null) {
            return true;
        }
        $normalizedPath = str_replace('\\', '/', $value);
        return preg_match('~[a-z][a-z0-9+.-]*://[^/\\s@]+@~i', $value) === 1
            // Public refusal evidence has no need to carry a query-bearing
            // absolute URI. Treat every query/fragment on one as sensitive,
            // rather than trying to maintain an inevitably incomplete list
            // of vendor signature and OAuth credential parameter names.
            || preg_match('~[a-z][a-z0-9+.-]*:[^\\s]*[?#][^\\s]*~i', $value) === 1
            || preg_match(
                '/[?&#](?:x-(?:amz|goog)-[^=&#]*(?:signature|credential|security[-_]?token)|[^=&#]*(?:secret|token|password|passwd|credential|signature|api[-_]?key|private[-_]?key)|sig)=/i',
                $value
            ) === 1
            || preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $value) === 1
            || preg_match('~/(?:Users|home)/~i', $normalizedPath) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
