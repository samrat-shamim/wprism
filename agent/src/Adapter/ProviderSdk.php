<?php
namespace WPrism;

/**
 * The read twin of \WPrism\Db: the sanctioned path for a provider's
 * decision-making database reads, plugin-agnostic and shared by every
 * adapter rather than re-copied into each.
 *
 * WordPress's wpdb read methods return an empty-looking value on a failed
 * query rather than throwing — get_var() returns false, the get_col/get_row/
 * get_results shape collapses to a non-array — so a provider that trusted the
 * bare return could clear a durable receipt on a query that never ran. Each
 * method below clears last_error, runs the read, and fails on the same twin
 * predicates Db uses for a mutation: the shape WordPress returns is the
 * failure shape for that read, OR the driver reported an error.
 *
 * Like Db, the context is caller-supplied and OPERATION-level, never
 * value-level: wpdb's last_error and the rendered SQL can echo option/meta
 * payloads, including the secrets WPrism is specifically responsible for keeping
 * out of diagnostics, so neither the SQL nor the driver text is ever placed
 * in the message — only the caller's operation context, which locates the
 * failure without carrying a value. "provider" names the caller generically
 * so no plugin identity leaks into the string either.
 *
 * The failure is a plain \RuntimeException — the same class the retired
 * per-adapter helpers threw — so \WPrism\Providers::invoke()'s catch(\Throwable)
 * redacts it into a provider/capability-only refusal exactly as before, and a
 * migrating adapter's calls are a pure substitution.
 *
 * $wpdb defaults to the global (matching Db.php) and is injectable so an
 * offline test can drive a fake. checked_get_results() additionally accepts
 * one fixed caller-owned refusal sentence: migrated adapters retain their
 * reviewed public diagnostics without reimplementing the checked read.
 */
final class ProviderSdk {
    public static function checked_get_var(string $sql, string $context, $wpdb = null): mixed {
        $wpdb ??= $GLOBALS['wpdb'];
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: provider checked read failed: $context");
        }
        return $value;
    }

    /** @return array<int,mixed> */
    public static function checked_get_col(string $sql, string $context, $wpdb = null): array {
        $wpdb ??= $GLOBALS['wpdb'];
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: provider checked read failed: $context");
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function checked_get_row(string $sql, string $context, $wpdb = null): ?array {
        $wpdb ??= $GLOBALS['wpdb'];
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: provider checked read failed: $context");
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public static function checked_get_results(
        string $sql,
        string $context,
        $wpdb = null,
        ?string $failureMessage = null
    ): array {
        $wpdb ??= $GLOBALS['wpdb'];
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException($failureMessage ?? "wprism: provider checked read failed: $context");
        }
        return $rows;
    }
}
