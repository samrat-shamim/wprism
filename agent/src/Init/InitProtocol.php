<?php
namespace WPrism;

/** Stable wire identifiers and fixed record slots for first initialization. */
final class InitProtocol {
    public const PLAN_FORMAT = 'wprism-init-plan/v1';
    public const ATTEMPT_FORMAT = 'wprism-init-attempt/v1';
    public const ATTEMPT_FILE = '.wprism-init-attempt';
    public const ATTEMPT_NEXT_FILE = '.wprism-init-attempt.next';
}
