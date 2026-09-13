<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/JsonRefs.php';
require_once __DIR__ . '/../Kernel/IdentityTokenCodec.php';
require_once __DIR__ . '/../Kernel/StructuredReferenceCodec.php';
require_once __DIR__ . '/../Kernel/RecordFields.php';
require_once __DIR__ . '/../Kernel/EncodedText.php';
require_once __DIR__ . '/../Kernel/BlockValueGrammar.php';
require_once __DIR__ . '/Tokens.php';

/** Value framing belongs to the block grammar; identity and text rewriting stay in the shared codecs. */
final class BlockValueCodec {
    /** @param callable(int,string):void $unmapped */
    public static function capture(mixed $value, array $rule, Tokens $tokens, callable $unmapped, string $where): mixed {
        self::assert_value($value, $rule, false, $where);
        if (isset($rule['object_fields'])) {
            foreach ($value as $field => &$child) {
                $child = self::capture($child, $rule['object_fields'][$field], $tokens, $unmapped, "$where.$field");
            }
            unset($child);
            return $value;
        }
        if (isset($rule['enum'])) return $value;
        $value = EncodedText::decode_if_declared($value, $rule, $where);
        if (isset($rule[RecordFields::FIELD])) $value = RecordFields::capture($value, $rule[RecordFields::FIELD]);
        $lookup = static function (int $id, string $kind) use ($tokens, $unmapped, $rule, $where): ?string {
            $token = $kind === 'user' ? $tokens->user_id_to_token($id) : $tokens->id_to_token($id, $kind);
            if ($token === null && ($rule['on_unmapped'] ?? null) === 'refuse') {
                throw new \RuntimeException("wprism: $where refuses an unmapped $kind reference");
            }
            if ($token === null && $kind !== 'user') $unmapped($id, $kind);
            return $token;
        };
        if (isset($rule['ref'])) {
            $kind = str_ends_with($rule['ref'], '[]') ? substr($rule['ref'], 0, -2) : $rule['ref'];
            foreach (self::scalar_values($value, $rule, false) as $one) {
                if (!self::unset_value($one) && ($kind !== 'user' || isset($rule['on_unmapped']))) $lookup((int) $one, $kind);
            }
            if (!str_ends_with($rule['ref'], '[]') && self::unset_value($value)) return $value;
            return $tokens->meta_value_to_tokens($value, $rule);
        }
        $value = StructuredReferenceCodec::capture($value, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null,
            $lookup, static function (string $warning) use ($tokens): void { $tokens->warnings[] = $warning; });
        return $tokens->plain_data_capture($value);
    }

    public static function apply(mixed $value, array $rule, Tokens $tokens, string $where): mixed {
        self::assert_value($value, $rule, true, $where);
        if (isset($rule['object_fields'])) {
            foreach ($value as $field => &$child) {
                $child = self::apply($child, $rule['object_fields'][$field], $tokens, "$where.$field");
            }
            unset($child);
            return $value;
        }
        if (isset($rule['enum'])) return $value;
        if (array_key_exists(EncodedText::FIELD, $rule)) {
            return EncodedText::encode($tokens->detokenize_text($value), $rule, $where);
        }
        if (isset($rule['ref'])) {
            if (!str_ends_with($rule['ref'], '[]') && self::unset_value($value)) return $value;
            return $tokens->meta_tokens_to_value($value, $rule);
        }
        return StructuredReferenceCodec::apply($tokens->plain_data_apply($value),
            $rule['json_refs'] ?? [], $rule['key_refs'] ?? null,
            static fn(string $token): int => $tokens->token_to_id($token));
    }

    /** Pure, value-free refusals shared by capture, immutable compilation, lint and apply. */
    public static function assert_value(mixed $value, array $rule, bool $canonical, string $where): void {
        BlockValueGrammar::assert_structured_keyspaces($rule, $where);
        if (($rule['class'] ?? '') === 'derived') {
            throw new \RuntimeException("wprism: $where is derived and must be absent from canonical block attributes");
        }
        $nodes = 0;
        self::assert_json($value, 0, $nodes, $where);
        if (isset($rule['object_fields'])) {
            // PHP's associative JSON decoder cannot distinguish {} from [].
            // A nonempty exact object preserves shape without guessing defaults.
            if (!is_array($value) || array_is_list($value) || array_diff_key($value, $rule['object_fields'])) {
                throw new \RuntimeException("wprism: $where requires a nonempty object containing only declared fields");
            }
            foreach ($value as $field => $child) {
                self::assert_value($child, $rule['object_fields'][$field], $canonical, "$where.$field");
            }
            return;
        }
        if (isset($rule['enum'])) {
            if (!in_array($value, $rule['enum'], true)) {
                throw new \RuntimeException("wprism: $where requires a declared literal value");
            }
            return;
        }
        if (array_key_exists(EncodedText::FIELD, $rule)) {
            if ($canonical) EncodedText::assert_canonical($value, $rule, $where);
            else EncodedText::decode($value, $rule, $where);
        }
        if (isset($rule[RecordFields::FIELD])) RecordFields::assert_value($value, $rule[RecordFields::FIELD], $canonical, $where);
        if (isset($rule['ref'])) {
            $list = str_ends_with($rule['ref'], '[]');
            $kind = $list ? substr($rule['ref'], 0, -2) : $rule['ref'];
            if ($list && ($canonical || ($rule['cast'] ?? '') !== 'csv')
                && (!is_array($value) || !array_is_list($value))) {
                throw new \RuntimeException("wprism: $where requires a reference list");
            }
            if (!$canonical && ($rule['cast'] ?? '') === 'csv'
                && (!is_string($value) || ($value !== '' && preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $value) !== 1))) {
                throw new \RuntimeException("wprism: $where requires canonical positive decimal CSV references");
            }
            foreach (self::scalar_values($value, $rule, $canonical) as $one) {
                self::assert_ref($one, $kind, $canonical,
                    ($rule['cast'] ?? '') === 'csv' ? 'string' : ($rule['cast'] ?? null), $where, !$list);
            }
            return;
        }
        if ((isset($rule['json_refs']) || isset($rule['key_refs'])) && !is_array($value)) {
            throw new \RuntimeException("wprism: $where requires a structured reference container");
        }
        foreach ($rule['json_refs'] ?? [] as $ref) {
            JsonRefs::walk($value, JsonRefs::parse_path($ref['path']),
                static function (&$container, $key, string $locator) use ($ref, $canonical): void {
                    self::assert_ref($container[$key], $ref['kind'], $canonical, $ref['cast'] ?? null, $locator, true);
                }, $where);
        }
        if (isset($rule['key_refs'])) {
            $keyRule = $rule['key_refs'];
            $check = static function (&$container, $key, string $locator) use ($keyRule, $canonical): void {
                $map = $container[$key];
                if (!is_array($map)) throw new \RuntimeException("wprism: $locator requires a reference-keyed map");
                foreach (array_keys($map) as $mapKey) {
                    self::assert_ref($mapKey, $keyRule['kind'], $canonical, null, $locator, false);
                }
            };
            if (isset($keyRule['path'])) {
                JsonRefs::walk($value, JsonRefs::parse_path($keyRule['path']), $check, $where);
            } else {
                $wrapper = ['root' => $value];
                $check($wrapper, 'root', $where);
            }
        }
    }

    private static function scalar_values(mixed $value, array $rule, bool $canonical): array {
        if (!$canonical && ($rule['cast'] ?? '') === 'csv') return $value === '' ? [] : explode(',', $value);
        return str_ends_with($rule['ref'], '[]') ? $value : [$value];
    }

    private static function unset_value(mixed $value): bool {
        return in_array($value, [null, '', 0, '0'], true);
    }

    private static function assert_ref(mixed $value, string $kind, bool $canonical, ?string $cast, string $where, bool $allowUnset): void {
        if ($allowUnset && ($value === null || $value === ''
            || ($cast === 'string' ? $value === '0' : $value === 0))) return;
        if ($canonical && is_string($value)) {
            if ($kind === 'user' && preg_match('/^user:[^\x00-\x1f\x7f]+$/Du', $value) === 1) return;
            try {
                $identity = IdentityTokenCodec::decode($value);
                if ($identity['kind'] === $kind && IdentityTokenCodec::encode($kind, $identity['uuid']) === $value) return;
            } catch (\RuntimeException) {}
        }
        if (!$canonical && (($cast === 'string' && is_string($value)
                && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (string) (int) $value === $value)
            || ($cast !== 'string' && is_int($value) && $value > 0))) return;
        throw new \RuntimeException("wprism: $where requires " . ($canonical
            ? 'a token in its declared reference keyspace' : 'a positive reference in its declared native scalar type'));
    }

    private static function assert_json(mixed $value, int $depth, int &$nodes, string $where): void {
        if ($depth > 64 || ++$nodes > 100000 || is_object($value) || is_resource($value)
            || (is_float($value) && !is_finite($value)) || (is_string($value) && preg_match('//u', $value) !== 1)) {
            throw new \RuntimeException("wprism: $where requires bounded plain JSON data");
        }
        if (is_array($value)) {
            foreach ($value as $key => $one) {
                if (is_string($key) && preg_match('//u', $key) !== 1) {
                    throw new \RuntimeException("wprism: $where requires UTF-8 object keys");
                }
                self::assert_json($one, $depth + 1, $nodes, $where);
            }
        }
    }
}
