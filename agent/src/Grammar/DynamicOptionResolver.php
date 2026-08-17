<?php
namespace Duo;

/**
 * Pure resolution of manifest-declared dynamic option names.
 *
 * The resolver deliberately knows only the already-validated manifest rows
 * and the source-level autoload default supplied by Policy.  It does not
 * load Policy (or WordPress): Capture, Apply, authorization, and the public
 * Policy facades retain their existing environment-specific responsibilities.
 */
final class DynamicOptionResolver {
    /**
     * @param list<array<string,mixed>> $manifests
     * @param \Closure(array<string,mixed>, array<string,mixed>):array<string,mixed> $withOptionAutoload
     */
    public function __construct(
        private array $manifests,
        private \Closure $withOptionAutoload
    ) {}

    /** @return array<string, array{prefix:string,resolver:string,sub_keys:array<string,array>,autoload:?string}> */
    public function dynamic_options(): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['dynamic_options'] ?? [] as $key => $rule) {
                if (isset($out[$key])) {
                    continue;
                }
                $out[$key] = ($this->withOptionAutoload)($rule, $manifest);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return ?array{name:string,class:string,sub_keys:array<string,array>,autoload:?string} */
    public function resolve_dynamic_option(string $key, string $resolvedValue): ?array {
        $declaration = $this->dynamic_options()[$key] ?? null;
        if ($declaration === null) {
            return null;
        }
        return [
            'name' => $declaration['prefix'] . $resolvedValue,
            'class' => 'env',
            'sub_keys' => $declaration['sub_keys'],
            'autoload' => $declaration['autoload'] ?? null,
        ];
    }

    /** @param array<string,string> $resolvedValues */
    public function is_dynamic_option_residue(string $liveName, array $resolvedValues): bool {
        foreach ($this->dynamic_options() as $declaration) {
            $prefix = $declaration['prefix'];
            if (!str_starts_with($liveName, $prefix)) {
                continue;
            }
            $resolvedValue = $resolvedValues[$declaration['resolver']] ?? null;
            if ($resolvedValue === null) {
                continue;
            }
            return $liveName !== ($prefix . $resolvedValue);
        }
        return false;
    }

    /** @param array<string,string> $resolvedValues
     * @return ?array{class:string,sub_keys:array<string,array>,autoload:?string}
     */
    public function dynamic_option_rule_for_name(string $name, array $resolvedValues): ?array {
        foreach ($this->dynamic_options() as $key => $declaration) {
            if (!str_starts_with($name, $declaration['prefix'])) {
                continue;
            }
            $resolvedValue = $resolvedValues[$declaration['resolver']] ?? null;
            if ($resolvedValue === null) {
                throw new \RuntimeException(
                    "duo: dynamic_options.$key declares resolver '{$declaration['resolver']}' but this caller supplied no "
                    . 'value for it (supplied: ' . (($resolvedValues === []) ? 'none' : implode(', ', array_keys($resolvedValues)))
                    . ') — the resolver vocabulary is engine-owned and every declared resolver must be resolved by the '
                    . 'engine call site, not skipped'
                );
            }
            $resolved = $this->resolve_dynamic_option($key, $resolvedValue);
            if ($resolved !== null && $resolved['name'] === $name) {
                return ['class' => $resolved['class'], 'sub_keys' => $resolved['sub_keys'], 'autoload' => $resolved['autoload']];
            }
        }
        return null;
    }

    /** @return ?array{class:string,sub_keys:array<string,array>,autoload:?string} */
    public function dynamic_option_rule_for_prefix(string $name): ?array {
        foreach ($this->dynamic_options() as $declaration) {
            if (str_starts_with($name, $declaration['prefix'])) {
                return [
                    'class' => 'env',
                    'sub_keys' => $declaration['sub_keys'],
                    'autoload' => $declaration['autoload'] ?? null,
                ];
            }
        }
        return null;
    }
}
