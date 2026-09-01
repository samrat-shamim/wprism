<?php
namespace WPrism;

/**
 * Named runtime boundaries needed while constructing apply collaborators.
 *
 * Call sites use named arguments so unlike the former positional closure
 * bundle, adjacent capabilities cannot silently trade responsibilities.
 */
final class ApplyServiceCallbacks {
    /** @var \Closure():void */
    public readonly \Closure $consumeDeleteAuthority;
    /** @var \Closure():void */
    public readonly \Closure $verifyDeleteCommit;
    /** @var \Closure():void */
    public readonly \Closure $endDeleteTransaction;

    public function __construct(
        /** @var \Closure():array */
        public readonly \Closure $taxonomyOwnership,
        /** @var \Closure(string):void */
        public readonly \Closure $renewPromotionLock,
        /** @var \Closure():void */
        public readonly \Closure $renewRegenerationLease,
        /** @var \Closure():void */
        public readonly \Closure $renewProviderLease,
        /** @var \Closure(array,array,array,array,array):void */
        public readonly \Closure $lockDeleteGuards,
        /** @var \Closure(array,array,array,bool,array,array,bool):(\Closure():void) */
        public readonly \Closure $recheckDeleteGuard,
        /** @var \Closure(string,string):bool */
        public readonly \Closure $selectionDeclaresChannelFor,
        /** @var \Closure(string):bool */
        public readonly \Closure $selectionDeclaresEntityBatchFor,
        /** @var \Closure(string):bool */
        public readonly \Closure $selectionTriggersProviderActionFor,
        /** @var \Closure(string):bool */
        public readonly \Closure $pinnedProviderActionOwns,
        /** @var \Closure(string,string,int,string,?string,?string,string):void */
        public readonly \Closure $upsertMeta,
        ?\Closure $consumeDeleteAuthority = null,
        ?\Closure $verifyDeleteCommit = null,
        ?\Closure $endDeleteTransaction = null
    ) {
        $unbound = static function (): void {
            throw new \RuntimeException(
                'wprism: deletion writer-exclusion callback is not configured'
            );
        };
        $this->consumeDeleteAuthority = $consumeDeleteAuthority ?? $unbound;
        $this->verifyDeleteCommit = $verifyDeleteCommit ?? $unbound;
        $this->endDeleteTransaction = $endDeleteTransaction ?? static function (): void {};
    }
}
