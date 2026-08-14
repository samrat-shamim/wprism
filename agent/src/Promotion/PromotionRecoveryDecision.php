<?php
declare(strict_types=1);

namespace Duo;

/**
 * The recovery choice which is allowed to accompany a promotion session.
 *
 * This is deliberately smaller than a product ReleasePlan.  It records only
 * the current recovery provider/profile selection and a redacted identity for
 * the configuration that produced that selection.  The identity is a digest,
 * never provider configuration or credentials.  A session may carry no
 * witness only when it is a legacy record being inspected for recovery; a new
 * forward mutation must use a bound value and compare it again before work.
 */
final class PromotionRecoveryDecision {
    public const FORMAT = 'duo-promotion-recovery-decision/v1';

    /** @var array{format:string,provider:string,profile:string,configuration_identity_sha256:string,decision_sha256:string} */
    private array $payload;

    /** @param array<string,mixed> $payload */
    private function __construct(array $payload) {
        $this->payload = $payload;
    }

    /**
     * Build a decision from the three facts that are safe to persist.
     *
     * @param string $provider selected current provider identity
     * @param string $profile selected current recovery profile identity
     * @param string $configurationIdentitySha256 redacted configuration digest
     */
    public static function select(
        string $provider,
        string $profile,
        string $configurationIdentitySha256
    ): self {
        $body = [
            'format' => self::FORMAT,
            'provider' => self::identifier($provider, 'provider'),
            'profile' => self::identifier($profile, 'profile'),
            'configuration_identity_sha256' => self::digest(
                $configurationIdentitySha256,
                'configuration identity'
            ),
        ];
        return new self($body + ['decision_sha256' => self::digestForBody($body)]);
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expected = [
            'configuration_identity_sha256',
            'decision_sha256',
            'format',
            'profile',
            'provider',
        ];
        if ($keys !== $expected
            || ($payload['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('malformed promotion recovery decision');
        }
        if (!is_string($payload['provider'] ?? null)
            || !is_string($payload['profile'] ?? null)
            || !is_string($payload['configuration_identity_sha256'] ?? null)
            || !is_string($payload['decision_sha256'] ?? null)) {
            throw new \InvalidArgumentException('malformed promotion recovery decision');
        }
        $body = [
            'format' => self::FORMAT,
            'provider' => self::identifier($payload['provider'], 'provider'),
            'profile' => self::identifier($payload['profile'], 'profile'),
            'configuration_identity_sha256' => self::digest(
                $payload['configuration_identity_sha256'],
                'configuration identity'
            ),
        ];
        $decision = self::digest($payload['decision_sha256'], 'decision');
        if (!hash_equals($decision, self::digestForBody($body))) {
            throw new \InvalidArgumentException('promotion recovery decision digest does not match its fields');
        }
        return new self($body + ['decision_sha256' => $decision]);
    }

    /** @return array{format:string,provider:string,profile:string,configuration_identity_sha256:string,decision_sha256:string} */
    public function toArray(): array {
        return $this->payload;
    }

    public function provider(): string { return $this->payload['provider']; }
    public function profile(): string { return $this->payload['profile']; }
    public function configurationIdentitySha256(): string {
        return $this->payload['configuration_identity_sha256'];
    }
    public function decisionSha256(): string { return $this->payload['decision_sha256']; }

    public function same(self $other): bool {
        return hash_equals($this->decisionSha256(), $other->decisionSha256())
            && self::canonicalBytes($this->payload) === self::canonicalBytes($other->payload);
    }

    /** Canonical bytes are the durable comparison and wire representation. */
    public function canonical(): string { return self::canonicalBytes($this->payload); }

    /** @return array{format:string,provider:string,profile:string,configuration_identity_sha256:string,decision_sha256:string} */
    public static function decodeWire(string $wire): array {
        $encoded = strtr($wire, '-_', '+/');
        $encoded .= str_repeat('=', (4 - (strlen($encoded) % 4)) % 4);
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new \InvalidArgumentException('malformed promotion recovery decision wire');
        }
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new \InvalidArgumentException('malformed promotion recovery decision wire');
        }
        $decision = self::fromArray($json);
        if ($decision->canonical() !== $decoded) {
            throw new \InvalidArgumentException('noncanonical promotion recovery decision wire');
        }
        return $decision->toArray();
    }

    public function encodeWire(): string {
        return rtrim(strtr(base64_encode($this->canonical()), '+/', '-_'), '=');
    }

    private static function digestForBody(array $body): string {
        return hash('sha256', self::FORMAT . "\0" . self::canonicalBytes($body));
    }

    private static function identifier(string $value, string $label): string {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("malformed promotion recovery decision $label");
        }
        return $value;
    }

    private static function digest(string $value, string $label): string {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("malformed promotion recovery decision $label digest");
        }
        return $value;
    }

    /** @param mixed $value */
    private static function canonicalBytes($value): string {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map([self::class, 'canonicalBytes'], $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $child) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException('promotion recovery decision object key is not a string');
                }
                $parts[] = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . ':' . self::canonicalBytes($child);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        throw new \InvalidArgumentException('promotion recovery decision contains an unsupported value');
    }
}
