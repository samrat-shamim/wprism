<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Opaque proof of the physical database session that owns an authored
 * transaction. CONNECTION_ID() alone is reusable after a reconnect; the
 * session variable is generated and witnessed on that same server session.
 */
final readonly class TransactionAuthority {
    public function __construct(
        private string $connectionId,
        private string $sessionNonce
    ) {
        if (preg_match('/^[1-9][0-9]*$/D', $connectionId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sessionNonce) !== 1) {
            throw new \InvalidArgumentException('database transaction authority is malformed');
        }
    }

    public function connection_id(): string {
        return $this->connectionId;
    }

    public function session_nonce(): string {
        return $this->sessionNonce;
    }

    public function equals(self $other): bool {
        return hash_equals($this->connectionId, $other->connectionId)
            && hash_equals($this->sessionNonce, $other->sessionNonce);
    }
}
