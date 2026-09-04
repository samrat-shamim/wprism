<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}
require_once __DIR__ . '/LockedOptionRows.php';
require_once __DIR__ . '/NativeDatabaseProfile.php';
require_once __DIR__ . '/OptionState.php';
require_once __DIR__ . '/TransactionalTableBoundary.php';
require_once __DIR__ . '/TransientDbException.php';

/**
 * Hook-free, exact persistence for one bounded physical wp_options row.
 *
 * A process crash after COMMIT drops WordPress's request-local cache, so a
 * verified post-outcome purge closes that topology. Persistent caches cannot
 * be closed: a competing pre-commit database read may publish old bytes after
 * any purge/readback proof, without a core generation/CAS fence.
 */
final class ExactOptionWriter {
    private const MAX_OPTION_NAME_BYTES = 764;
    private const MAX_OPTION_NAME_CHARACTERS = 191;
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const PRECONDITION_ANY = 'any';
    private const PRECONDITION_ABSENT = 'absent';
    private const PRECONDITION_VALUE = 'value';

    /**
     * WordPress 6.9 stores `auto` when a new option's nullable autoload
     * decision remains undecided. Calling that filterable heuristic would
     * execute plugin code inside this engine transaction, so absent/preserve
     * policy resolves to the same valid physical state deterministically.
     */
    private const DEFAULT_NEW_ROW_AUTOLOAD = 'auto';

    /**
     * @return array{changed:bool,previously_present:bool,previously_nonempty:bool,autoload:string}
     */
    public static function upsert_plain(
        string $name,
        string $value,
        ?string $newRowAutoload,
        string $context
    ): array {
        $result = self::persist(
            $name,
            $value,
            $newRowAutoload,
            self::PRECONDITION_ANY,
            null,
            $context
        );
        unset($result['precondition_met']);
        return $result;
    }

    /** Insert exactly once; false means a row already held the locked key. */
    public static function insert_plain_if_absent(
        string $name,
        string $value,
        ?string $newRowAutoload,
        string $context
    ): bool {
        return self::persist(
            $name,
            $value,
            $newRowAutoload,
            self::PRECONDITION_ABSENT,
            null,
            $context
        )['precondition_met'];
    }

    /** Replace only the exact locked raw value; false is a CAS mismatch. */
    public static function replace_plain_if_value(
        string $name,
        string $expectedValue,
        string $value,
        string $context
    ): bool {
        self::assert_context($context);
        self::assert_value($expectedValue, $context . ' expected value');
        return self::persist(
            $name,
            $value,
            null,
            self::PRECONDITION_VALUE,
            $expectedValue,
            $context
        )['precondition_met'];
    }

    /**
     * @return array{changed:bool,precondition_met:bool,previously_present:bool,previously_nonempty:bool,autoload:string}
     */
    private static function persist(
        string $name,
        string $value,
        ?string $newRowAutoload,
        string $precondition,
        ?string $expectedValue,
        string $context
    ): array {
        self::assert_context($context);
        self::assert_name($name, $context);
        self::assert_value($value, $context . ' value');
        $newRowAutoload = self::new_row_autoload($newRowAutoload, $context);
        self::assert_cache_boundary($context);

        global $wpdb;
        $table = is_object($wpdb) ? ($wpdb->options ?? null) : null;
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new \RuntimeException("wprism: $context requires canonical wp_options access");
        }

        $open = false;
        $terminalAttempted = false;
        $changed = false;
        $previouslyPresent = false;
        $previouslyNonempty = false;
        $autoload = $newRowAutoload;
        try {
            Db::start_repeatable_read(
                $context . ' transaction start',
                new NativeDatabaseProfile([], [$table])
            );
            $open = true;
            $authority = Db::transaction_authority($context . ' transaction authority');
            $index = TransactionalTableBoundary::full_width_unique_lock_index(
                $table,
                'option_name',
                $authority,
                $context
            );
            $before = LockedOptionRows::read_optional(
                $name,
                $index,
                $authority,
                $context . ' preimage'
            );
            $previouslyPresent = $before !== null;
            $previouslyNonempty = $before !== null && $before['option_value'] !== '';
            if ($before !== null) {
                self::assert_physical_autoload($before['autoload'], $context . ' existing row');
                $autoload = $before['autoload'];
            }

            $preconditionMet = match ($precondition) {
                self::PRECONDITION_ANY => true,
                self::PRECONDITION_ABSENT => $before === null,
                self::PRECONDITION_VALUE => $before !== null
                    && $expectedValue !== null
                    && hash_equals($expectedValue, $before['option_value']),
                default => throw new \LogicException('wprism: exact option writer received an unknown precondition'),
            };
            if ($preconditionMet && ($before === null || !hash_equals($value, $before['option_value']))) {
                if ($before === null) {
                    $affected = Db::insert(
                        $table,
                        [
                            'option_name' => $name,
                            'option_value' => $value,
                            'autoload' => $newRowAutoload,
                        ],
                        ['%s', '%s', '%s'],
                        $context . ' insert'
                    );
                } else {
                    // Existing physical autoload is target state. Neither an
                    // explicit create policy nor WordPress's filterable
                    // auto-state recomputation may rewrite it on an update.
                    $affected = Db::update(
                        $table,
                        ['option_value' => $value],
                        ['option_name' => $name],
                        ['%s'],
                        ['%s'],
                        $context . ' update'
                    );
                }
                if ($affected !== 1) {
                    throw new DatabaseMutationException($context . ' changed an unexpected number of option rows');
                }
                $changed = true;
                $after = LockedOptionRows::read_optional(
                    $name,
                    $index,
                    $authority,
                    $context . ' postimage'
                );
                if ($after === null
                    || !hash_equals($name, $after['option_name'])
                    || !hash_equals($value, $after['option_value'])
                    || !hash_equals($autoload, $after['autoload'])) {
                    throw new DatabaseMutationException($context . ' exact option postimage did not match');
                }
            }

            $terminalAttempted = true;
            try {
                Db::commit($context . ' transaction commit');
            } catch (DatabaseMutationException|TransientDbException $notCommitted) {
                Db::rollback_after_failure(
                    $notCommitted,
                    $context . ' transaction rollback after refused commit'
                );
                $open = false;
                $terminalAttempted = false;
                throw $notCommitted;
            }
            $open = false;
        } catch (\Throwable $failure) {
            if ($open && !$terminalAttempted) {
                Db::rollback_after_failure($failure, $context . ' transaction rollback');
            } elseif ($terminalAttempted) {
                // COMMIT may have crossed the server while its response was
                // lost. Purge stale local cache best-effort, but preserve
                // Db's unresolved database outcome as the primary failure.
                self::invalidate_cache_best_effort($name);
            }
            throw $failure;
        }

        if ($changed) {
            self::invalidate_cache($name, $context);
        }
        return [
            'changed' => $changed,
            'precondition_met' => $preconditionMet,
            'previously_present' => $previouslyPresent,
            'previously_nonempty' => $previouslyNonempty,
            'autoload' => $autoload,
        ];
    }

    private static function assert_cache_boundary(string $context): void {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            throw new \RuntimeException(
                "wprism: $context refuses hook-free option persistence while a persistent external object "
                    . 'cache is active; WordPress exposes no backend-independent version/CAS fence across '
                    . 'the options/alloptions/notoptions groups'
            );
        }
        if (!function_exists('wp_cache_delete') || !function_exists('wp_cache_get')) {
            throw new \RuntimeException("wprism: $context requires verifiable WordPress option-cache primitives");
        }
    }

    private static function invalidate_cache(string $name, string $context): void {
        $failures = self::invalidate_cache_attempt($name);
        if ($failures !== []) {
            $failures = self::invalidate_cache_attempt($name);
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                "wprism: $context database transaction committed, but option-cache invalidation remained incomplete "
                    . 'after exhaustive retry; recovery_required; ' . implode('; ', $failures)
            );
        }
    }

    private static function invalidate_cache_best_effort(string $name): void {
        try {
            $failures = self::invalidate_cache_attempt($name);
            if ($failures !== []) {
                self::invalidate_cache_attempt($name);
            }
        } catch (\Throwable) {
            // The unresolved COMMIT exception remains the only trustworthy
            // outcome. A cache throwable cannot turn uncertainty into either
            // a database success or a database failure.
        }
    }

    /** @return list<string> */
    private static function invalidate_cache_attempt(string $name): array {
        $failures = [];
        foreach ([$name, 'alloptions', 'notoptions'] as $key) {
            try {
                wp_cache_delete($key, 'options');
                $found = null;
                wp_cache_get($key, 'options', true, $found);
                if ($found !== false) {
                    throw new \RuntimeException('cache key remained present');
                }
            } catch (\Throwable $failure) {
                $message = $failure->getMessage();
                $failures[] = 'key=' . strlen($key) . ':' . substr(hash('sha256', $key), 0, 16)
                    . ',failure=' . get_class($failure) . ':' . strlen($message) . ':'
                    . substr(hash('sha256', $message), 0, 16);
            }
        }
        return $failures;
    }

    private static function new_row_autoload(?string $autoload, string $context): string {
        if ($autoload === null || $autoload === 'preserve') {
            return self::DEFAULT_NEW_ROW_AUTOLOAD;
        }
        self::assert_physical_autoload($autoload, $context . ' new-row policy');
        return $autoload;
    }

    private static function assert_physical_autoload(string $autoload, string $context): void {
        if (!in_array($autoload, OptionState::AUTOLOAD_VALUES, true)) {
            throw new \InvalidArgumentException("wprism: $context has unsupported physical autoload storage");
        }
    }

    private static function assert_name(string $name, string $context): void {
        $characters = preg_match('//u', $name) === 1 ? preg_match_all('/./us', $name) : false;
        // wp_protect_special_option() reserves these cache-control identities;
        // this exception boundary cannot invoke its translated wp_die() path.
        if ($name === ''
            || strlen($name) > self::MAX_OPTION_NAME_BYTES
            || !is_int($characters)
            || $characters > self::MAX_OPTION_NAME_CHARACTERS
            || in_array($name, ['alloptions', 'notoptions'], true)
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \InvalidArgumentException("wprism: $context received an invalid option identity");
        }
    }

    private static function assert_value(string $value, string $context): void {
        if (strlen($value) > self::MAX_OPTION_VALUE_BYTES) {
            throw new \InvalidArgumentException("wprism: $context exceeds the bounded option-value frontier");
        }
    }

    private static function assert_context(string $context): void {
        if ($context === ''
            || strlen($context) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $context) === 1) {
            throw new \InvalidArgumentException('wprism: exact option writer received an invalid operation context');
        }
    }

}
