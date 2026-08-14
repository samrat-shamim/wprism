<?php
namespace Duo;

require_once __DIR__ . '/ApplyWorkset.php';
require_once __DIR__ . '/DeletionAuthority.php';

/** Complete named input for the single authored database transaction. */
final class AuthoredTransactionRequest {
    public function __construct(
        public readonly ApplyWorkset $workset,
        public readonly DeletionAuthority $deletionAuthority,
        public readonly bool $scoped,
        public readonly ?array $scopeContract,
        public readonly bool $performTransaction,
        public readonly ?int $defaultAuthor
    ) {}
}
