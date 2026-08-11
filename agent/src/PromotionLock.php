<?php
namespace Duo;

/**
 * Backward-compatible name for the target promotion lease service.
 *
 * New code should depend on PromotionLease.  This facade preserves the
 * historical static API and wire/error behavior for existing integrations.
 */
require_once __DIR__ . '/PromotionLease.php';

final class PromotionLock extends PromotionLease {}
