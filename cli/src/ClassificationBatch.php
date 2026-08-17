<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Review artifact for classifying a large pre-Duo queue without pretending
 * old writes have journal proposals. The artifact contains no live values:
 * it is the already-redacted `pending` evidence plus explicit operator
 * decisions. A digest binds it to the exact queue that was reviewed, so an
 * aged site changing between export and apply refuses instead of applying a
 * stale blanket decision to a newly-discovered key.
 */
final class ClassificationBatch {
    public const FORMAT = 'duo-classification-batch/v1';

    /** @param list<array<string,mixed>> $items */
    public static function template(string $environment, array $items): array {
        $decisions = [];
        foreach ($items as $item) {
            $row = [
                'section' => self::requiredString($item, 'section', 'pending item'),
                'key' => self::requiredString($item, 'key', 'pending item'),
                'class' => null,
                'ref' => null,
                'cast' => null,
                'allow_secret' => false,
                'proposal' => is_string($item['proposal'] ?? null) ? $item['proposal'] : null,
                'evidence' => is_array($item['evidence'] ?? null) ? $item['evidence'] : (object) [],
            ];
            if (is_array($item['ref_hint'] ?? null)) {
                $row['ref_hint'] = $item['ref_hint'];
            }
            if (is_string($item['secret'] ?? null) && $item['secret'] !== '') {
                $row['secret'] = $item['secret'];
            }
            $decisions[] = $row;
        }
        return [
            'format' => self::FORMAT,
            'environment' => $environment,
            'queue_sha256' => self::queueHash($items),
            'decisions' => $decisions,
        ];
    }

    /** Stable across associative-key insertion order; list order remains meaningful. */
    public static function queueHash(array $items): string {
        $json = json_encode(self::normalize($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('duo: could not encode the pending queue for classification-batch binding');
        }
        return hash('sha256', $json);
    }

    public static function encode(array $batch): string {
        $json = json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('duo: could not encode classification batch');
        }
        return $json . "\n";
    }

    public static function load(string $path): array {
        if (!is_file($path)) {
            throw new \RuntimeException("duo: classification batch not found: $path");
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException("duo: could not read classification batch: $path");
        }
        $batch = json_decode($raw, true);
        if (!is_array($batch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("duo: invalid classification-batch JSON in $path: " . json_last_error_msg());
        }
        return $batch;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{decisions:list<array<string,string>>,needAllowSecret:bool}
     */
    public static function validate(array $batch, string $environment, array $items): array {
        if (($batch['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException(
                "duo: unsupported classification batch format '" . (string) ($batch['format'] ?? '')
                . "' (expected " . self::FORMAT . ')'
            );
        }
        if (($batch['environment'] ?? null) !== $environment) {
            throw new \RuntimeException(
                "duo: classification batch is for environment '" . (string) ($batch['environment'] ?? '')
                . "', not '$environment'"
            );
        }
        $currentHash = self::queueHash($items);
        if (!is_string($batch['queue_sha256'] ?? null)
            || !hash_equals($currentHash, (string) $batch['queue_sha256'])) {
            throw new \RuntimeException(
                "duo: classification batch is stale; pending queue is now $currentHash. Export and review a fresh batch."
            );
        }
        if (!is_array($batch['decisions'] ?? null) || !array_is_list($batch['decisions'])) {
            throw new \RuntimeException('duo: classification batch decisions must be a JSON list');
        }

        $pending = [];
        foreach ($items as $item) {
            $section = self::requiredString($item, 'section', 'pending item');
            $key = self::requiredString($item, 'key', 'pending item');
            $pending[$section . "\0" . $key] = $item;
        }

        $decisions = [];
        $seen = [];
        $incomplete = [];
        $needAllowSecret = false;
        foreach ($batch['decisions'] as $i => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("duo: classification batch decisions[$i] must be an object");
            }
            $section = self::requiredString($row, 'section', "decisions[$i]");
            $key = self::requiredString($row, 'key', "decisions[$i]");
            if (preg_match('/[;,=]/', $key)) {
                throw new \RuntimeException(
                    "duo: $section:$key cannot travel through wp duo classify's semicolon-joined --set grammar"
                );
            }
            $identity = $section . "\0" . $key;
            if (isset($seen[$identity])) {
                throw new \RuntimeException("duo: duplicate classification decision for $section:$key");
            }
            $seen[$identity] = true;
            if (!isset($pending[$identity])) {
                throw new \RuntimeException("duo: classification decision $section:$key is not in the bound pending queue");
            }
            if (!in_array($section, ['options', 'post_meta', 'term_meta', 'user_meta', 'scope'], true)) {
                throw new \RuntimeException(
                    "duo: $section:$key requires a manifest/schema change and cannot be resolved by a site-policy classification batch"
                );
            }

            $class = $row['class'] ?? null;
            if ($class === null || $class === '') {
                $incomplete[] = "$section:$key";
                continue;
            }
            $allowed = $section === 'scope'
                ? ['authored', 'runtime', 'derived', 'env']
                : ['authored', 'runtime', 'derived', 'env', 'managed'];
            if (!is_string($class) || !in_array($class, $allowed, true)) {
                throw new \RuntimeException(
                    "duo: invalid class for $section:$key (expected " . implode('|', $allowed) . ')'
                );
            }
            $decision = ['section' => $section, 'key' => $key, 'class' => $class];
            foreach (['ref', 'cast'] as $field) {
                $value = $row[$field] ?? null;
                if ($value !== null && $value !== '') {
                    if (!is_string($value)) {
                        throw new \RuntimeException("duo: $section:$key $field must be a string or null");
                    }
                    $decision[$field] = $value;
                }
            }
            if ($section === 'scope') {
                if (!preg_match('/^(post_type|taxonomy):.+$/', $key)) {
                    throw new \RuntimeException(
                        "duo: scope key '$key' must be post_type:<name> or taxonomy:<name>"
                    );
                }
                if (isset($decision['ref']) || isset($decision['cast'])) {
                    throw new \RuntimeException("duo: scope:$key accepts class only (no ref or cast)");
                }
            } else {
                if (isset($decision['ref'])
                    && !preg_match('/^(post|term|user)(\[\])?$/', $decision['ref'])) {
                    throw new \RuntimeException(
                        "duo: invalid ref '{$decision['ref']}' for $section:$key "
                        . '(expected post|term|user, optionally suffixed with [])'
                    );
                }
                if (isset($decision['cast'])
                    && !in_array($decision['cast'], ['string', 'csv'], true)) {
                    throw new \RuntimeException(
                        "duo: invalid cast '{$decision['cast']}' for $section:$key (expected string|csv)"
                    );
                }
            }
            $secret = is_string($pending[$identity]['secret'] ?? null)
                ? (string) $pending[$identity]['secret']
                : null;
            $allowSecret = $row['allow_secret'] ?? false;
            if (!is_bool($allowSecret)) {
                throw new \RuntimeException("duo: $section:$key allow_secret must be boolean");
            }
            if ($allowSecret && ($class !== 'authored' || $secret === null)) {
                throw new \RuntimeException(
                    "duo: $section:$key sets allow_secret without an authored, secret-flagged pending item"
                );
            }
            if ($class === 'authored' && $secret !== null && !$allowSecret) {
                throw new \RuntimeException(
                    "duo: refusing authored decision for secret-flagged $section:$key ($secret); set allow_secret=true in the reviewed row"
                );
            }
            if ($allowSecret) {
                $needAllowSecret = true;
            }
            $decisions[] = $decision;
        }
        $missing = array_diff_key($pending, $seen);
        foreach ($missing as $item) {
            $incomplete[] = (string) $item['section'] . ':' . (string) $item['key'];
        }
        if ($incomplete) {
            sort($incomplete, SORT_STRING);
            throw new \RuntimeException(
                'duo: classification batch is incomplete; every bound pending item needs an explicit class:'
                . "\n  - " . implode("\n  - ", $incomplete)
            );
        }
        return ['decisions' => $decisions, 'needAllowSecret' => $needAllowSecret];
    }

    private static function requiredString(array $row, string $field, string $where): string {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("duo: $where.$field must be a non-empty string");
        }
        return $value;
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'normalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child);
        }
        return $value;
    }
}
