<?php
namespace Duo;

/** Stable wire identifiers and fixed record slots for first initialization. */
final class InitProtocol {
    public const PLAN_FORMAT = 'duo-init-plan/v1';
    public const ATTEMPT_FORMAT = 'duo-init-attempt/v1';
    public const ATTEMPT_FILE = '.duo-init-attempt';
    public const ATTEMPT_NEXT_FILE = '.duo-init-attempt.next';
}
