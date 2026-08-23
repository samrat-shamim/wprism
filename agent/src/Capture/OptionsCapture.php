<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

/**
 * Read-only discovery and canonical capture of WordPress option entities.
 *
 * The caller retains transaction orchestration and the loud-and-blocking
 * gates. This boundary owns option reads, classification, canonical names,
 * value encoding, lifecycle tombstones, and the evidence those gates consume.
 */
final class OptionsCapture {
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
            $this->capture_option_sub_keys($name, $rule, $forceUnresolvedRefs, $liveCanonicalNames, $out);
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
                ['sub_keys' => $resolved['sub_keys'], 'autoload' => $resolved['autoload']],
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
                $omitUnsetScalarRef
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
        $captured = [];
        foreach ($rule['sub_keys'] ?? [] as $subKey => $subRule) {
            if (($subRule['class'] ?? '') !== 'authored' || !array_key_exists($subKey, $live)) {
                continue;
            }
            $subVal = $live[$subKey];
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
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $name
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return ['option_value' => (string) $row['option_value'], 'autoload' => (string) $row['autoload']];
    }

    /** @return array<string,string> */
    private function all_options_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['option_name']] = (string) $row['option_value'];
        }
        return $out;
    }

    private function option_ref_tokens(
        string $name,
        $value,
        string $ref,
        bool $forceUnresolvedRefs = false,
        bool $omitUnsetScalar = false
    ) {
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $ok = [];
            foreach ((array) $value as $v) {
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
