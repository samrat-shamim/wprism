<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Grammar/SubKeyGrammar.php';

/**
 * Read-only discovery and canonical capture of WordPress option entities.
 *
 * The caller retains transaction orchestration and the loud-and-blocking
 * gates. This boundary owns option reads, classification, canonical names,
 * value encoding, lifecycle tombstones, and the evidence those gates consume.
 */
final class OptionsCapture {
    private const MAX_DISCOVERED_OPTIONS = 200000;
    private const MAX_OPTION_NAME_CHARACTERS = 191;
    private const MAX_OPTION_NAME_BYTES = 764;
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_DISCOVERY_BYTES = 134217728;
    private Policy $policy;
    private Tokens $tokens;
    private \Closure $guardSecret;
    private \Closure $classifyScope;
    private \Closure $rowExists;
    /** @var string[] */
    private array $unclassified = [];
    /** @var array<int,array{option:string,kind:string,id:int,target_type:string}> */
    private array $unscopedRefs = [];
    /** @var array<int,array{option:string,id_kind:string,id:int}> */
    private array $unscopedOptionNameRefs = [];

    public function __construct(
        Policy $policy,
        Tokens $tokens,
        callable $guardSecret,
        callable $classifyScope,
        callable $rowExists
    ) {
        $this->policy = $policy;
        $this->tokens = $tokens;
        $this->guardSecret = \Closure::fromCallable($guardSecret);
        $this->classifyScope = \Closure::fromCallable($classifyScope);
        $this->rowExists = \Closure::fromCallable($rowExists);
    }

    /**
     * @return array{
     *   document:array,
     *   unclassified:string[],
     *   unscoped_refs:array<int,array{option:string,kind:string,id:int,target_type:string}>,
     *   unscoped_option_name_refs:array<int,array{option:string,id_kind:string,id:int}>
     * }
     */
    public function capture(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousDocument = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false,
        bool $strictReadOnly = false
    ): array {
        $this->unclassified = [];
        $this->unscopedRefs = [];
        $this->unscopedOptionNameRefs = [];

        $out = [];
        $processed = [];
        $liveCanonicalNames = [];
        foreach ($this->policy->authored_options() as $name => $rule) {
            $processed[$name] = true;
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$name] = true;
            $v = PlainData::decode($row['option_value'], "option $name");
            PlainData::assert($v, "option $name");
            ($this->guardSecret)('options', $name, $v, $rule);
            $captured = $this->capture_value($name, $v, $rule, $forceUnresolvedRefs);
            if (!$captured['included']) {
                continue;
            }
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
            $out[$name] = OptionState::present($captured['value'], $row['autoload']);
        }

        // One journal-independent scan feeds namespace discovery and every
        // option-name-reference matcher.
        $allOptionValues = $this->all_options_map();
        foreach (array_keys($allOptionValues) as $name) {
            $owner = $this->policy->option_namespace($name);
            if ($owner === null) {
                continue;
            }
            $rule = $this->policy->owned_option_rule_via_interpreter($name, $allOptionValues);
            if (isset($processed[$name]) || $this->policy->match_option_name_ref($name) !== null) {
                continue;
            }
            if ($rule === null) {
                $this->unclassified[] = "options:$name (owner candidate {$owner['owner']}; namespace matched without a classification)";
                continue;
            }
            $processed[$name] = true;
            if (($rule['class'] ?? '') !== 'authored' || !empty($rule['sub_keys'])) {
                continue;
            }
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$name] = true;
            $v = PlainData::decode($row['option_value'], "option $name");
            PlainData::assert($v, "option $name");
            ($this->guardSecret)('options', $name, $v, $rule);
            $captured = $this->capture_value($name, $v, $rule, $forceUnresolvedRefs);
            if ($captured['included']) {
                OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
                $out[$name] = OptionState::present($captured['value'], $row['autoload']);
            }
        }

        foreach ($this->policy->sub_keyed_options() as $name => $rule) {
            $details = $this->policy->option_rule_details((string) $name);
            $source = is_string($details['source'] ?? null) ? $details['source'] : null;
            $this->capture_option_sub_keys(
                $name,
                $rule,
                $source,
                $forceUnresolvedRefs,
                $liveCanonicalNames,
                $out
            );
        }

        foreach ($this->policy->dynamic_options() as $key => $decl) {
            $resolvedValue = match ($decl['resolver']) {
                'active_stylesheet' => array_key_exists('active_stylesheet', $dynamicResolverValues)
                    ? (string) $dynamicResolverValues['active_stylesheet']
                    : (string) get_option('stylesheet'),
                default => throw new \RuntimeException(
                    "duo: dynamic_options.$key declares unsupported resolver '{$decl['resolver']}'"
                ),
            };
            $resolved = $this->policy->resolve_dynamic_option($key, $resolvedValue);
            if ($resolved === null) {
                continue;
            }
            $this->capture_option_sub_keys(
                $resolved['name'],
                [
                    'class' => $resolved['class'],
                    'sub_keys' => $resolved['sub_keys'],
                    'closed_sub_keys' => $resolved['closed_sub_keys'] ?? false,
                    'autoload' => $resolved['autoload'],
                ],
                'dynamic_options',
                $forceUnresolvedRefs,
                $liveCanonicalNames,
                $out
            );
        }

        foreach (array_keys($allOptionValues) as $name) {
            $details = $this->policy->option_name_ref_match_details((string) $name);
            if ($details === null || ($details['rule']['class'] ?? '') !== 'authored') {
                continue;
            }
            $rule = $details['rule'];
            $m = $details['matches'];
            $rawId = $m['id'][0] ?? null;
            $id = Policy::strict_positive_local_id($rawId);
            if ($id === null) {
                throw new \RuntimeException(
                    "duo: option '$name' captures an invalid local id in option_name_refs; refusing capture"
                );
            }
            $offset = (int) $m['id'][1];
            $length = strlen((string) $m['id'][0]);
            $token = $this->tokens->id_to_token($id, $rule['id_kind']);
            if ($token === null) {
                if (($mint || $strictReadOnly) && !$forceUnresolvedRefs
                    && ($this->rowExists)($this->policy, $rule['id_kind'], $id)) {
                    $this->unscopedOptionNameRefs[] = [
                        'option' => $name,
                        'id_kind' => $rule['id_kind'],
                        'id' => $id,
                    ];
                } else {
                    $this->tokens->warnings[] = "option $name: unmapped {$rule['id_kind']} id $id dropped (option_name_refs)";
                }
                continue;
            }
            $canonicalName = substr_replace($name, $token, $offset, $length);
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$canonicalName] = true;
            $v = PlainData::decode($row['option_value'], "option $name");
            PlainData::assert($v, "option $name");
            if (empty($rule['allow_secret'])) {
                $secretLabel = Secrets::hard_match_deep($v);
                if ($secretLabel !== null) {
                    throw new \RuntimeException(
                        "duo: secret guard tripped — option '$name' looks like a $secretLabel but is classified "
                        . "authored (option_name_refs); refusing to capture it into state/.\n"
                        . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
                        . 'If this is a false positive, declare "allow_secret": true on its option_name_refs rule.'
                    );
                }
            }
            $v = $this->tokens->struct_capture($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
            $out[$canonicalName] = OptionState::present($v, $row['autoload']);
        }

        foreach (['active_plugins', 'template', 'stylesheet'] as $managedOption) {
            $row = $this->read_option_row($managedOption);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$managedOption] = true;
            $v = PlainData::decode($row['option_value'], "option $managedOption");
            PlainData::assert($v, "option $managedOption");
            $v = $managedOption === 'active_plugins'
                ? array_values(array_map('strval', (array) $v))
                : (string) $v;
            $rule = $this->policy->option_rule($managedOption) ?? [];
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$managedOption'");
            $out[$managedOption] = OptionState::present($v, $row['autoload']);
        }

        $previousOptionValues = null;
        foreach ($previousDocument === null ? [] : OptionState::records($previousDocument) as $name => $record) {
            $bindDynamic = $bindMissingDynamicDesired
                && ($record['state'] ?? null) === 'present'
                && $this->policy->dynamic_option_rule_for_prefix((string) $name) !== null
                && !isset($out[$name]);
            if ($bindDynamic) {
                $out[$name] = OptionState::deleted($record);
                continue;
            }
            if (isset($out[$name]) || isset($liveCanonicalNames[$name])) {
                continue;
            }
            if ($record['state'] === 'deleted') {
                $out[$name] = $record;
                continue;
            }
            $details = str_contains((string) $name, '{{')
                ? $this->policy->canonical_option_name_ref_details((string) $name)
                : $this->policy->option_rule_details_for_option(
                    (string) $name,
                    $previousOptionValues ??= OptionState::values($previousDocument)
                );
            $rule = $details['rule'] ?? [];
            if ($record['state'] === 'present'
                && ($rule['class'] ?? null) === 'authored' && empty($rule['sub_keys'])) {
                $out[$name] = OptionState::deleted($record, !empty($rule['deletion_witness']));
            } else {
                $out[$name] = OptionState::absent();
            }
        }

        $required = array_fill_keys(array_keys($this->policy->authored_options()), true);
        $required += array_fill_keys(array_keys($this->policy->sub_keyed_options()), true);
        foreach (['active_plugins', 'template', 'stylesheet'] as $managedOption) {
            if (($this->policy->option_rule($managedOption)['class'] ?? null) === 'managed') {
                $required[$managedOption] = true;
            }
        }
        foreach ($required as $name => $_) {
            if (!isset($out[$name])) {
                $out[$name] = OptionState::absent();
            }
        }

        return [
            'document' => OptionState::document($out),
            'unclassified' => $this->unclassified,
            'unscoped_refs' => $this->unscopedRefs,
            'unscoped_option_name_refs' => $this->unscopedOptionNameRefs,
        ];
    }

    /** @return array{included:bool,value:mixed} */
    private function capture_value(
        string $ctx,
        $v,
        array $rule,
        bool $forceUnresolvedRefs,
        bool $omitUnsetScalarRef = false
    ): array {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = StructuredValue::decode($v, $rule, "option $ctx");
            return ['included' => true, 'value' => $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            )];
        }
        if (!empty($rule['plain_data'])) {
            return ['included' => true, 'value' => $this->tokens->plain_data_capture($v)];
        }
        if (!empty($rule['ref'])) {
            $captured = $this->option_ref_tokens(
                $ctx,
                $v,
                $rule['ref'],
                $forceUnresolvedRefs,
                $omitUnsetScalarRef,
                $rule['cast'] ?? null
            );
            return ['included' => $captured !== null, 'value' => $captured];
        }
        if (is_string($v)) {
            return ['included' => true, 'value' => $this->tokens->tokenize_text($v)];
        }
        return ['included' => true, 'value' => $v];
    }

    /**
     * @param array<string,bool> $liveCanonicalNames
     * @param array<string,mixed> $out
     */
    private function capture_option_sub_keys(
        string $name,
        array $rule,
        ?string $ruleSource,
        bool $forceUnresolvedRefs,
        array &$liveCanonicalNames,
        array &$out
    ): void {
        $row = $this->read_option_row($name);
        if ($row === null) {
            return;
        }
        $liveCanonicalNames[$name] = true;
        $live = PlainData::decode($row['option_value'], "option $name");
        PlainData::assert($live, "option $name");
        if (!is_array($live)) {
            throw new \RuntimeException(
                "duo: option '$name' declares sub_keys but its live value is not array-shaped (got "
                . get_debug_type($live) . ') — sub_keys assumes the option decodes to a plain '
                . 'PHP-serialized map (an associative array keyed by sub-key name), not a scalar or object'
            );
        }
        SubKeyGrammar::assert_closed_value($name, $rule, $live, 'source');
        $rawAuthored = [];
        foreach ($rule['sub_keys'] ?? [] as $subKey => $subRule) {
            if (($subRule['class'] ?? '') !== 'authored' || !array_key_exists($subKey, $live)) {
                continue;
            }
            $rawAuthored[$subKey] = $live[$subKey];
        }
        // Native defaults and canonical values must enter before the ordinary
        // capture codec. Otherwise an interpreter-added ref/text sibling can
        // bypass tokenization and secret guarding, while a hook can mutate an
        // already-tokenized value whose source bytes it no longer observes.
        $rawAuthored = $this->policy->normalize_captured_option_sub_keys_via_interpreter(
            $name,
            $rawAuthored,
            $rule,
            $ruleSource
        );
        $captured = [];
        foreach ($rawAuthored as $subKey => $subVal) {
            $subRule = (array) (($rule['sub_keys'] ?? [])[$subKey] ?? []);
            $ctx = "$name.$subKey";
            if (is_string($subVal)) {
                ($this->guardSecret)('options', $ctx, $subVal, $subRule);
            } elseif (empty($subRule['allow_secret'])) {
                $secretLabel = Secrets::hard_match_deep($subVal);
                if ($secretLabel !== null) {
                    throw new \RuntimeException(
                        "duo: secret guard tripped — option '$ctx' looks like a $secretLabel but is classified "
                        . "authored (sub_keys); refusing to capture it into state/.\n"
                        . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
                        . 'If this is a false positive, declare "allow_secret": true on its sub_keys rule.'
                    );
                }
            }
            $capturedValue = $this->capture_value($ctx, $subVal, $subRule, $forceUnresolvedRefs, true);
            if ($capturedValue['included']) {
                $captured[$subKey] = $capturedValue['value'];
            }
        }
        if ($captured) {
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
            $out[$name] = OptionState::present($captured, $row['autoload']);
        }
    }

    /** @return ?array{option_value:string,autoload:string} */
    private function read_option_row(string $name): ?array {
        global $wpdb;
        self::assert_option_name($name, 'exact option read');
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
            . "OCTET_LENGTH(autoload) AS autoload_bytes FROM {$wpdb->options} "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), ARRAY_A);
        if (!is_array($sizes)
            || !array_is_list($sizes)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: exact option size preflight failed');
        }
        if (count($sizes) > 1) {
            throw new \RuntimeException('duo: exact option read found duplicate option_name rows');
        }
        if ($sizes === []) {
            return null;
        }
        $size = $sizes[0];
        $valueBytes = is_array($size) ? self::canonical_size($size['option_value_bytes'] ?? null) : null;
        $autoloadBytes = is_array($size) ? self::canonical_size($size['autoload_bytes'] ?? null) : null;
        if (!is_array($size)
            || array_keys($size) !== ['option_name', 'option_value_bytes', 'autoload_bytes']
            || !is_string($size['option_name'] ?? null)
            || $valueBytes === null
            || $autoloadBytes === null
            || $valueBytes > self::MAX_OPTION_VALUE_BYTES
            || $autoloadBytes > 20) {
            throw new \RuntimeException('duo: exact option size preflight returned a malformed or oversized row');
        }
        if (!hash_equals($name, $size['option_name'])) {
            throw new \RuntimeException('duo: exact option read found a collation-equal option_name alias');
        }

        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value, autoload FROM {$wpdb->options} "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: exact option read failed');
        }
        if (count($rows) > 1) {
            throw new \RuntimeException('duo: exact option read found duplicate option_name rows');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !is_string($row['autoload'] ?? null)
            || strlen($row['option_value']) !== $valueBytes
            || strlen($row['autoload']) !== $autoloadBytes) {
            throw new \RuntimeException('duo: exact option read returned a malformed or oversized row');
        }
        if (!hash_equals($name, $row['option_name'])) {
            throw new \RuntimeException('duo: exact option read found a collation-equal option_name alias');
        }
        return ['option_value' => $row['option_value'], 'autoload' => $row['autoload']];
    }

    /** @return array<string,string> */
    private function all_options_map(): array {
        global $wpdb;
        $wpdb->last_error = '';
        $statsRows = $wpdb->get_results(
            'SELECT COUNT(*) AS row_count, '
            . 'COALESCE(SUM(OCTET_LENGTH(option_name) + OCTET_LENGTH(option_value)), 0) AS total_bytes, '
            . 'COALESCE(MAX(OCTET_LENGTH(option_name)), 0) AS max_name_bytes, '
            . 'COALESCE(MAX(CHAR_LENGTH(option_name)), 0) AS max_name_characters, '
            . 'COALESCE(MAX(OCTET_LENGTH(option_value)), 0) AS max_value_bytes '
            . "FROM {$wpdb->options}",
            ARRAY_A
        );
        if (!is_array($statsRows)
            || !array_is_list($statsRows)
            || count($statsRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: bounded option namespace size preflight failed');
        }
        $stats = $statsRows[0];
        $rowCount = is_array($stats) ? self::canonical_size($stats['row_count'] ?? null) : null;
        $totalBytes = is_array($stats) ? self::canonical_size($stats['total_bytes'] ?? null) : null;
        $maxNameBytes = is_array($stats) ? self::canonical_size($stats['max_name_bytes'] ?? null) : null;
        $maxNameCharacters = is_array($stats)
            ? self::canonical_size($stats['max_name_characters'] ?? null)
            : null;
        $maxValueBytes = is_array($stats) ? self::canonical_size($stats['max_value_bytes'] ?? null) : null;
        if (!is_array($stats)
            || array_keys($stats) !== [
                'row_count', 'total_bytes', 'max_name_bytes', 'max_name_characters', 'max_value_bytes',
            ]
            || $rowCount === null
            || $totalBytes === null
            || $maxNameBytes === null
            || $maxNameCharacters === null
            || $maxValueBytes === null) {
            throw new \RuntimeException('duo: option namespace size preflight returned malformed statistics');
        }
        if ($rowCount > self::MAX_DISCOVERED_OPTIONS) {
            throw new \RuntimeException('duo: option namespace discovery exceeds the bounded row limit');
        }
        if ($maxNameBytes > self::MAX_OPTION_NAME_BYTES
            || $maxNameCharacters > self::MAX_OPTION_NAME_CHARACTERS
            || $maxValueBytes > self::MAX_OPTION_VALUE_BYTES
            || $totalBytes > self::MAX_DISCOVERY_BYTES) {
            throw new \RuntimeException('duo: option namespace discovery exceeds the bounded byte frontier');
        }

        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} "
            . 'ORDER BY option_name ASC, option_id ASC LIMIT ' . (self::MAX_DISCOVERED_OPTIONS + 1),
            ARRAY_A
        );
        if (!is_array($rows) || !array_is_list($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: bounded option namespace discovery read failed');
        }
        if (count($rows) !== $rowCount) {
            throw new \RuntimeException('duo: option namespace rows changed after the bounded size preflight');
        }
        $out = [];
        $aggregateBytes = 0;
        foreach ($rows as $position => $row) {
            if (!is_array($row)
                || array_keys($row) !== ['option_name', 'option_value']
                || !is_string($row['option_name'] ?? null)
                || !is_string($row['option_value'] ?? null)) {
                throw new \RuntimeException(
                    "duo: option namespace discovery returned a malformed row at bounded position $position"
                );
            }
            self::assert_option_name($row['option_name'], 'option namespace discovery');
            if (strlen($row['option_value']) > self::MAX_OPTION_VALUE_BYTES) {
                throw new \RuntimeException('duo: option namespace discovery found an oversized option value');
            }
            if (array_key_exists($row['option_name'], $out)) {
                throw new \RuntimeException('duo: option namespace discovery found duplicate option_name rows');
            }
            $aggregateBytes += strlen($row['option_name']) + strlen($row['option_value']);
            if ($aggregateBytes > self::MAX_DISCOVERY_BYTES) {
                throw new \RuntimeException('duo: option namespace discovery exceeds the bounded byte limit');
            }
            $out[$row['option_name']] = $row['option_value'];
        }
        if ($aggregateBytes !== $totalBytes) {
            throw new \RuntimeException('duo: option namespace values disagree with the bounded size preflight');
        }
        return $out;
    }

    private static function assert_option_name(string $name, string $purpose): void {
        $characters = strlen($name) <= self::MAX_OPTION_NAME_BYTES
            ? preg_match_all('/./us', $name)
            : false;
        if ($name === ''
            || strlen($name) > self::MAX_OPTION_NAME_BYTES
            || !is_int($characters)
            || $characters > self::MAX_OPTION_NAME_CHARACTERS
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \RuntimeException("duo: $purpose received a malformed or oversized option name");
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
    }

    private function option_ref_tokens(
        string $name,
        $value,
        string $ref,
        bool $forceUnresolvedRefs = false,
        bool $omitUnsetScalar = false,
        ?string $cast = null
    ) {
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $ok = [];
            // PMPro 3.8.x stores pmpro_level_order and
            // pmpro_hideadslevels as comma-delimited strings, while their
            // manifest refs are list-shaped. Treating that string as one
            // PHP array element silently retained only its leading integer
            // (`(int) "2,1,3" === 2`) and lost every later relationship.
            // The same cast already has a rule-aware apply codec in Tokens;
            // split here before the option-specific scope triage so each id
            // is independently mapped, dropped, or reported.
            $values = $cast === 'csv'
                ? array_values(array_filter(
                    array_map('trim', explode(',', (string) $value)),
                    static fn(string $part): bool => $part !== ''
                ))
                : (array) $value;
            foreach ($values as $v) {
                $id = (int) $v;
                if ($id === 0) {
                    continue;
                }
                $tok = $this->tokens->id_to_token($id, $kind);
                if ($tok === null) {
                    if (!$this->queue_or_warn_unscoped($name, $kind, $id, $forceUnresolvedRefs)) {
                        $this->tokens->warnings[] = "option $name: unmanaged $kind id $v dropped";
                    }
                    continue;
                }
                $ok[] = $tok;
            }
            return $ok;
        }
        $id = (int) $value;
        if ($omitUnsetScalar && $id <= 0) {
            // WordPress uses negative ids as cached "no object" sentinels
            // (custom_css_post_id=-1 after a frontend lookup). A sub-key ref
            // has no portable target in either zero spelling, so omit it
            // without accusing an unmanaged entity; whole-option scalar refs
            // retain the stricter durable-zero contract below.
            return null;
        }
        if ($id === 0) {
            // A whole authored scalar option uses 0 as durable "unset"
            // state (page_on_front/page_for_posts/privacy policy). Returning
            // null used to turn that into OptionState::absent, which means
            // "no intent" and left a hostile target's old page id untouched.
            // Sub-key refs retain their established deletion semantics: a
            // zero theme-mod pointer means the authored key is gone.
            return $omitUnsetScalar ? null : 0;
        }
        $tok = $this->tokens->id_to_token($id, $ref);
        if ($tok === null) {
            if (!$this->queue_or_warn_unscoped($name, $ref, $id, $forceUnresolvedRefs)) {
                $this->tokens->warnings[] = "option $name: unmanaged $ref id $id — key skipped";
            }
            return null;
        }
        return $tok;
    }

    private function queue_or_warn_unscoped(string $option, string $kind, int $id, bool $force): bool {
        $targetType = ($this->classifyScope)($id, $kind, $force);
        if ($targetType === null) {
            return false;
        }
        $this->unscopedRefs[] = [
            'option' => $option,
            'kind' => $kind,
            'id' => $id,
            'target_type' => $targetType,
        ];
        return true;
    }
}
