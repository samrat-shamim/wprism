<?php
declare(strict_types=1);

namespace Duo;

/**
 * Pure, closed declaration grammar for engine-native actions.
 *
 * This leaf is safe to load while validating a repository: it contains no
 * WordPress calls, provider loading, receipt storage, or mutation code.  The
 * historical NativeActions facade delegates here so existing callers retain
 * their API while policy/grammar code no longer depends on the executor.
 */
final class NativeActionCatalog {
    /** @var array<string,array<string,array{type:string,required:bool,pattern?:string}>> */
    private const ACTIONS = [
        'transient.delete' => [
            'name' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,170}$/D',
            ],
        ],
    ];

    /** @return list<string> */
    public static function vocabulary(): array {
        return array_keys(self::ACTIONS);
    }

    /** @return array<string,array<string,array{type:string,required:bool,pattern?:string}>> */
    public static function argSchemas(): array {
        $out = [];
        foreach (self::ACTIONS as $action => $args) {
            $out[$action] = [];
            foreach ($args as $key => $rule) {
                $out[$action][$key] = array_intersect_key(
                    $rule,
                    ['type' => true, 'required' => true, 'pattern' => true]
                );
            }
        }
        return $out;
    }

    /** @return array<string,array{type:string,required:bool,pattern?:string}> */
    public static function schema(string $action): array {
        $schema = self::ACTIONS[$action] ?? null;
        if ($schema === null) {
            self::validate($action, [], "native action '$action'");
        }
        return $schema;
    }

    /** @param array<string,mixed> $args */
    public static function validate(string $action, array $args, string $where): void {
        $schema = self::ACTIONS[$action] ?? null;
        if ($schema === null) {
            throw new \RuntimeException(
                "duo: $where names unknown native action '$action' — the engine vocabulary is closed ("
                . implode(', ', self::vocabulary()) . '); a plugin-specific operation belongs in a provider'
            );
        }
        if (array_is_list($args) && $args !== []) {
            throw new \RuntimeException("duo: $where.args must be an object");
        }
        $unknown = array_diff(array_keys($args), array_keys($schema));
        if ($unknown !== []) {
            throw new \RuntimeException(
                "duo: $where.args contains unknown key(s) for native action '$action': "
                . implode(', ', $unknown)
            );
        }
        foreach ($schema as $key => $rule) {
            if (!array_key_exists($key, $args)) {
                if ($rule['required']) {
                    throw new \RuntimeException(
                        "duo: $where.args is missing required key '$key' for native action '$action'"
                    );
                }
                continue;
            }
            $value = $args[$key];
            if ($rule['type'] === 'string'
                && (!is_string($value) || preg_match((string) $rule['pattern'], $value) !== 1)) {
                throw new \RuntimeException(
                    "duo: $where.args.$key must be a bounded string matching {$rule['pattern']}"
                );
            }
        }
    }
}
