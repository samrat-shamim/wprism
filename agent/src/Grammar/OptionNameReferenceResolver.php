<?php
namespace WPrism;

/**
 * Pure resolution of manifest-declared option names containing local ids.
 *
 * The resolver deliberately knows only the already-validated manifest rows
 * and the source-level autoload default supplied by Policy. It does not load
 * Policy (or WordPress): Policy retains the established public compatibility
 * surface used by capture, apply, snapshot, and explanation callers.
 */
final class OptionNameReferenceResolver {
    /**
     * @param list<array<string,mixed>> $manifests
     * @param \Closure(array<string,mixed>, array<string,mixed>):array<string,mixed> $withOptionAutoload
     */
    public function __construct(
        private array $manifests,
        private \Closure $withOptionAutoload
    ) {}

    /**
     * @return array<int, array{match:string, id_kind:string, class:string, malformed_match?:string, json_refs?:array, key_refs?:array}>
     */
    public function rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['option_name_refs'] ?? [] as $rule) {
                $out[] = ($this->withOptionAutoload)($rule, $m);
            }
        }
        return $out;
    }

    /** @return ?array{rule:array,matches:array,source:string} */
    public function match_details(string $realOptionName): ?array {
        $matches = [];
        $malformed = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['option_name_refs'] ?? [] as $index => $rule) {
                $rule = ($this->withOptionAutoload)($rule, $manifest);
                $pattern = '/' . (string) ($rule['match'] ?? '') . '/';
                $captured = [];
                if (preg_match($pattern, $realOptionName, $captured, PREG_OFFSET_CAPTURE) === 1) {
                    if (!isset($captured['id'][0], $captured['id'][1])) {
                        throw new \RuntimeException(
                            "wprism: option_name_refs rule for option '$realOptionName' did not expose its named id capture"
                        );
                    }
                    if (self::strict_positive_local_id($captured['id'][0]) === null) {
                        throw new \RuntimeException(
                            "wprism: option '$realOptionName' captures an invalid local id in option_name_refs; "
                            . 'leading-zero, zero, and overflow spellings are refused'
                        );
                    }
                    $matches[] = [
                        'rule' => $rule,
                        'matches' => $captured,
                        'source' => (string) ($manifest['name'] ?? '?'),
                        'index' => (int) $index,
                    ];
                }
                $malformedPattern = $rule['malformed_match'] ?? null;
                if (is_string($malformedPattern) && $malformedPattern !== ''
                    && preg_match('/' . $malformedPattern . '/', $realOptionName) === 1) {
                    $malformed[] = [
                        'rule' => $rule,
                        'source' => (string) ($manifest['name'] ?? '?'),
                        'index' => (int) $index,
                    ];
                }
            }
        }
        if ($malformed) {
            $owners = array_map(
                static fn(array $entry): string => (string) $entry['source'],
                $malformed
            );
            throw new \RuntimeException(
                "wprism: option '$realOptionName' matches a malformed option_name_refs namespace "
                . '(invalid local id; refusing capture/apply) declared by ' . implode(', ', array_unique($owners))
            );
        }
        if (count($matches) > 1) {
            $owners = array_map(
                static fn(array $entry): string => (string) $entry['source'],
                $matches
            );
            throw new \RuntimeException(
                "wprism: option '$realOptionName' matches multiple option_name_refs rules (ambiguous ownership; "
                . 'refusing pin-order resolution): ' . implode(', ', $owners)
            );
        }
        if (!$matches) {
            return null;
        }
        return $matches[0];
    }

    /** @return int|null only an exact positive decimal local id is accepted. */
    public static function strict_positive_local_id($value): ?int {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        $id = (int) $digits;
        // Reject overflow rather than letting a huge decimal string saturate
        // to PHP_INT_MAX and accidentally resolve a different row.
        return $id > 0 && (string) $id === $digits ? $id : null;
    }

    /** @return array{rule:?array, source:?string} */
    public function canonical_details(string $name): array {
        $tokenCount = preg_match_all(
            '/\{\{([a-z][a-z0-9_]*):[0-9a-f-]{36}\}\}/',
            $name,
            $tokens,
            PREG_SET_ORDER
        );
        if ($tokenCount === false || $tokenCount === 0) {
            return ['rule' => null, 'source' => null];
        }
        if ($tokenCount !== 1) {
            throw new \RuntimeException(
                "wprism: canonical option key '$name' contains multiple embedded identity tokens; refusing ambiguity"
            );
        }
        $token = $tokens[0][0] ?? '';
        $tokenKind = (string) ($tokens[0][1] ?? '');
        $representative = str_replace($token, '1', $name);
        $details = $this->match_details($representative);
        if ($details === null) {
            $knownKind = false;
            foreach ($this->rules() as $rule) {
                if ((string) ($rule['id_kind'] ?? '') === $tokenKind) {
                    $knownKind = true;
                    break;
                }
            }
            if ($knownKind) {
                throw new \RuntimeException(
                    "wprism: canonical option token for id_kind '$tokenKind' is not owned by exactly one authored "
                    . 'option_name_refs rule'
                );
            }
            return ['rule' => null, 'source' => null];
        }
        if ((string) ($details['rule']['id_kind'] ?? '') !== $tokenKind
            || ($details['rule']['class'] ?? '') !== 'authored') {
            throw new \RuntimeException(
                "wprism: canonical option key '$name' has an identity token whose id_kind does not match its "
                . 'sole authored option_name_refs owner'
            );
        }
        return [
            'rule' => $details['rule'],
            'source' => $details['source'],
            'matches' => $details['matches'],
            'token_kind' => $tokenKind,
        ];
    }

    /** @return ?array */
    public function match(string $realOptionName): ?array {
        $details = $this->match_details($realOptionName);
        return $details['rule'] ?? null;
    }
}
