<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Opaque work-partition authority returned once to the owner of Db::start*().
 *
 * Construction, cloning, and deserialization confer no authority: the query
 * gate accepts only the exact object it minted while binding this profile.
 * ProviderDatabaseSession discards that return value before invoking native
 * code; capture/apply pass it only to engine semantic work owners, never hooks.
 */
final readonly class DatabaseWorkAuthority {}
