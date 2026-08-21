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
    /**
     * v2 (DUO-3496). A v1 row carried class/ref/cast/allow_secret and nothing
     * else, so it could not express a COMPLETE decision: the site grammar
     * demands `autoload` on an options rule classed authored or managed and a
     * boolean `required` on one classed env
     * (agent/src/Grammar/OptionGrammar.php:76-96 and :42-56), and a filled v1
     * batch therefore applied into a site.duo.json the very next `duo pending`
     * refused to load.
     *
     * Bumped rather than extended in place, on DUO-3489's own test — absence
     * must not read as a decision. Under v1 a missing `autoload` is
     * ambiguous in exactly the way that precedent refuses: it can mean "the
     * reviewer left the field blank" or "the exporter never offered a field
     * to fill", and only the format string tells the two apart. Refusing a v1
     * artifact as an unfilled field would send an operator to edit a form
     * that has no such field. The usual argument against a bump — migration
     * cost — does not apply here: the artifact is ephemeral and bound to
     * `queue_sha256`, so the remedy is one re-export of a document nobody
     * keeps.
     */
    public const FORMAT = 'duo-classification-batch/v2';

    /** The shape retired above; recognized by name so a stale artifact gets its own remedy. */
    public const FORMAT_WITHOUT_STORAGE_DECISIONS = 'duo-classification-batch/v1';

    /**
     * The agent's option-storage vocabulary, restated because the
     * orchestrator never loads agent code (the drop-in runs on the target;
     * this runs on the operator's host) — the same reason the class, ref and
     * cast vocabularies below are restated. `preserve` is
     * OptionGrammar::OPTION_AUTOLOAD_SENTINELS, the values are
     * OptionState::AUTOLOAD_VALUES, and
     * sandbox/tests/offline/cli/regress_classification_batch.php reads both
     * agent files and fails the gate if either list drifts from this one.
     */
    public const OPTION_AUTOLOAD_SENTINELS = ['preserve'];
    public const OPTION_AUTOLOAD_VALUES = ['yes', 'no', 'auto', 'on', 'off', 'auto-on', 'auto-off'];

    /** Option classes whose rule is refused at load time without an `autoload` declaration. */
    public const CLASSES_NEEDING_AUTOLOAD = ['authored', 'managed'];

    /** @param list<array<string,mixed>> $items */
    public static function template(string $environment, array $items): array {
        $decisions = [];
        foreach ($items as $item) {
            $section = self::requiredString($item, 'section', 'pending item');
            $row = [
                'section' => $section,
                'key' => self::requiredString($item, 'key', 'pending item'),
                'class' => null,
                'ref' => null,
                'cast' => null,
                'allow_secret' => false,
                'proposal' => is_string($item['proposal'] ?? null) ? $item['proposal'] : null,
                'evidence' => is_array($item['evidence'] ?? null) ? $item['evidence'] : (object) [],
            ];
            if ($section === 'options') {
                // Null, never prefilled — including from an observed storage
                // flag. `autoload: yes` is not a restatement of what the row
                // happens to hold today; it is a fleet-wide contract capture
                // then enforces, refusing any source row that disagrees
                // (agent/src/Grammar/OptionGrammar.php:67-71), and `preserve`
                // is the opposite decision — decline to pin the flag. An
                // observation cannot pick between those two, so filling
                // either from evidence would be the silent default the whole
                // grammar exists to refuse. Evidence-derived material stays
                // where `proposal` and `ref_hint` already put it: advisory,
                // beside the decision, never inside it.
                $row['autoload'] = null;
                $row['required'] = null;
            }
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
     * @return array{decisions:list<array<string,string|bool>>,needAllowSecret:bool}
     */
    public static function validate(array $batch, string $environment, array $items): array {
        if (($batch['format'] ?? null) === self::FORMAT_WITHOUT_STORAGE_DECISIONS) {
            // Named separately from the generic unsupported-format refusal
            // because the remedy is different and specific: this artifact is
            // not malformed, it is a form with missing fields, and no edit to
            // it can produce a complete decision.
            throw new \RuntimeException(
                'duo: classification batch format ' . self::FORMAT_WITHOUT_STORAGE_DECISIONS
                . ' predates the per-class decision fields an options row must carry'
                . ' (autoload for authored/managed, required for env); re-export the batch'
                . ' (duo classify <env> --export-batch=<path>) and review the fresh artifact'
            );
        }
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
        $undecided = [];
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
            // Last, so an unacknowledged authored secret above still refuses
            // first: that one is about what leaves the site, this one about
            // whether the decision is complete.
            $storage = self::storageDecision($row, $section, $key, $class);
            if ($storage['undecided'] !== null) {
                $undecided[] = $storage['undecided'];
                continue;
            }
            $decisions[] = $decision + $storage['fields'];
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
        if ($undecided) {
            sort($undecided, SORT_STRING);
            throw new \RuntimeException(
                'duo: classification batch is incomplete; these reviewed decisions still need the field the'
                . " site grammar demands before the rule can load:\n  - " . implode("\n  - ", $undecided)
            );
        }
        return ['decisions' => $decisions, 'needAllowSecret' => $needAllowSecret];
    }

    /**
     * The per-class completion an options rule needs, decided here rather
     * than discovered by the target (DUO-3496).
     *
     * `wp duo classify` writes into site.duo.json, and every later
     * Policy::load() runs OptionGrammar over what it wrote: an options rule
     * classed authored or managed without `autoload` and one classed env
     * without a boolean `required` are both refused there. Reading a blank
     * field as "the operator meant the default" is the one thing that grammar
     * forbids, so an unfilled field comes back for aggregation and the batch
     * refuses on the host — before the single remote write opens, which is
     * what keeps a refused batch mutation-free.
     *
     * @param array<string,mixed> $row
     * @return array{fields:array<string,mixed>,undecided:?string}
     */
    private static function storageDecision(array $row, string $section, string $key, string $class): array {
        $autoload = $row['autoload'] ?? null;
        $required = $row['required'] ?? null;
        if ($section !== 'options') {
            if ($autoload !== null || $required !== null) {
                throw new \RuntimeException(
                    "duo: $section:$key sets autoload/required, which belong only to an options rule"
                );
            }
            return ['fields' => [], 'undecided' => null];
        }
        // An inert field is an answer nothing asks for: the grammar reads
        // `autoload` only on an authored/managed rule and `required` only on
        // an env one, so accepting it elsewhere would let a reviewer believe
        // they decided something the target will never read.
        $needsAutoload = in_array($class, self::CLASSES_NEEDING_AUTOLOAD, true);
        if ($autoload !== null && !$needsAutoload) {
            throw new \RuntimeException(
                "duo: options:$key sets autoload with class=$class; the storage flag is read only for authored and managed option rules"
            );
        }
        if ($required !== null && $class !== 'env') {
            throw new \RuntimeException(
                "duo: options:$key sets required with class=$class; the provisioning decision is read only for env option rules"
            );
        }
        if ($needsAutoload) {
            if ($autoload === null) {
                return ['fields' => [], 'undecided' => "options:$key class=$class needs autoload=preserve"
                    . ' or an explicit supported autoload value ('
                    . implode('|', self::OPTION_AUTOLOAD_VALUES) . '); insertion may never guess'];
            }
            if (!is_string($autoload)
                || !in_array($autoload, array_merge(self::OPTION_AUTOLOAD_SENTINELS, self::OPTION_AUTOLOAD_VALUES), true)) {
                throw new \RuntimeException(
                    "duo: invalid autoload '" . (is_scalar($autoload) ? (string) $autoload : gettype($autoload))
                    . "' for options:$key (expected "
                    . implode('|', array_merge(self::OPTION_AUTOLOAD_SENTINELS, self::OPTION_AUTOLOAD_VALUES)) . ')'
                );
            }
            return ['fields' => ['autoload' => $autoload], 'undecided' => null];
        }
        if ($class === 'env') {
            if ($required === null) {
                return ['fields' => [], 'undecided' => "options:$key class=env needs an explicit boolean required"
                    . ' (true: an operator must provision this value on a fresh environment — a genuine secret or'
                    . ' site-identity value; false: plugin-internal bookkeeping that self-populates and is not'
                    . ' worth checklisting) — no silent default either way'];
            }
            if (!is_bool($required)) {
                throw new \RuntimeException(
                    "duo: options:$key required must be true or false, not "
                    . (is_scalar($required) ? "'" . (string) $required . "'" : gettype($required))
                );
            }
            return ['fields' => ['required' => $required], 'undecided' => null];
        }
        return ['fields' => [], 'undecided' => null];
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
