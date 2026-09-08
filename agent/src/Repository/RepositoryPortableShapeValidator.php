<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/StructuredReferenceCodec.php';

require_once __DIR__ . '/../Kernel/IdentityTokenCodec.php';
require_once __DIR__ . '/../Kernel/ReferenceCondition.php';

// This is a pure canonical-tree portability pass. Normal direct loads close
// every named collaborator; focused fixtures may preload narrow doubles, so
// retain the conditional boundary used by sibling compiler seams.
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}
if (!class_exists(OptionState::class, false)) {
    require_once __DIR__ . '/../Kernel/OptionState.php';
}
if (!class_exists(ScalarReferenceIntersection::class, false)) {
    require_once __DIR__ . '/../Kernel/ScalarReferenceIntersection.php';
}
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/SidebarState.php';
}
if (!class_exists(ReferenceRules::class, false)) {
    require_once __DIR__ . '/../Kernel/ReferenceRules.php';
}
if (!class_exists(JsonRefs::class, false)) {
    require_once __DIR__ . '/../Kernel/JsonRefs.php';
}
if (!class_exists(PlainData::class, false)) {
    require_once __DIR__ . '/../Kernel/PlainData.php';
}
if (!class_exists(PersonalData::class, false)) {
    require_once __DIR__ . '/../Kernel/PersonalData.php';
}
if (!class_exists(Secrets::class, false)) {
    require_once __DIR__ . '/../Kernel/Secrets.php';
}
require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
require_once __DIR__ . '/../Grammar/BlockValueCodec.php';
require_once __DIR__ . '/../Kernel/BlockAttributeReader.php';

final class RepositoryPortableShapeValidator {
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    private Policy $policy;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, \Closure $add) {
        $this->policy = $policy;
        $this->add = $add;
    }

    /**
     * Declared references must already be canonical tokens in the IR. A raw
     * numeric id is syntactically valid JSON and can even name a real row on
     * one target, but has no environment-independent meaning.
     *
     * @param array<string,array<string,mixed>> $tree
     */
    public function validate(array $tree): void {
        $rows = Snapshot::row_tables($this->policy);
        $metaByOwner = [];
        foreach (Snapshot::meta_tables($this->policy) as $name => $decl) {
            $metaByOwner[(string) ($decl['attached_to']['table'] ?? '')][$name] = $decl;
        }
        // The canonical option pass below can precede its referenced term in
        // tree order. Preserve every matching document in this pure index so
        // a duplicate UUID can never become a last-writer-wins taxonomy proof.
        $termTargets = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? null) !== 'term' || !is_array($entity['data'] ?? null)) {
                continue;
            }
            $uuid = $entity['data']['uuid'] ?? null;
            if (!is_string($uuid) || preg_match('/^' . self::UUID_PATTERN . '$/D', $uuid) !== 1) {
                continue; // RepositoryEntityParser owns malformed identity diagnostics.
            }
            $termTargets[$uuid][] = [
                'taxonomy' => is_string($entity['data']['taxonomy'] ?? null)
                    ? $entity['data']['taxonomy']
                    : null,
                'path' => (string) ($entity['path'] ?? ''),
            ];
        }
        foreach ($tree as $entity) {
            $path = $entity['path'];
            $d = $entity['data'];
            if ($entity['type'] === 'post') {
                if (method_exists($this->policy, 'body_mode')
                    && $this->policy->body_mode((string) ($d['type'] ?? '')) === 'blocks') {
                    $this->validate_block_values((string) ($entity['body'] ?? ''), $path);
                }
                if (($d['author'] ?? null) !== null
                    && (!is_string($d['author']) || !str_starts_with($d['author'], 'user:')
                        || strlen($d['author']) === 5)) {
                    $this->add('nonportable_reference', $path, 'author', 'post author must be a user:<login> token');
                }
                if (method_exists($this->policy, 'body_mode')
                    && $this->policy->body_mode((string) ($d['type'] ?? '')) === 'serialized') {
                    $this->validate_serialized_body((string) ($entity['body'] ?? ''), $path);
                }
                if (method_exists($this->policy, 'body_mode')
                    && $this->policy->body_mode((string) ($d['type'] ?? '')) === BodyRefGrammar::BODY_MODE) {
                    $this->validate_json_body((string) ($entity['body'] ?? ''), (string) $d['type'], $path);
                }
                if (($d['parent'] ?? null) !== null) {
                    $this->validate_declared_ref($d['parent'], 'post', $path, 'parent');
                }
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_post((string) $key, $meta) ?? [];
                    $this->validate_meta_value($value, $rule, $path, 'meta.' . $key);
                }
            } elseif ($entity['type'] === 'menu') {
                foreach ((array) ($d['items'] ?? []) as $i => $item) {
                    $kind = ($item['type'] ?? '') === 'post_type' ? 'post'
                        : (($item['type'] ?? '') === 'taxonomy' ? 'term' : null);
                    if ($kind !== null) {
                        $this->validate_declared_ref($item['ref'] ?? null, $kind, $path, "items[$i].ref");
                    }
                }
            } elseif ($entity['type'] === 'term') {
                $descriptionRule = $this->policy->description_reference_rule((string) ($d['taxonomy'] ?? ''));
                if ($descriptionRule !== null) {
                    $this->validate_structured_rule(
                        $d['description'] ?? null,
                        $descriptionRule,
                        $path,
                        'description'
                    );
                }
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_term((string) $key, $meta) ?? [];
                    $this->validate_meta_value($value, $rule, $path, 'meta.' . $key);
                }
            } elseif ($entity['type'] === 'options') {
                // issue #3263: an interpreter-classified option's ref kind (ACF's
                // options-page fields) needs the same document-sourced
                // sibling map meta_rule_for_post() above already gets from
                // $meta — options have no single owning entity, so this is
                // every present value plus valid v2 deletion witnesses in
                // this SAME document, built once.
                $allOptions = OptionState::classification_values($d);
                foreach (OptionState::records($d) as $name => $record) {
                    if ($record['state'] !== 'present') {
                        continue;
                    }
                    $value = $record['value'];
                    $details = str_contains((string) $name, '{{')
                        ? $this->policy->canonical_option_name_ref_details((string) $name)
                        : $this->policy->option_rule_details_for_option((string) $name, $allOptions);
                    $rule = $details['rule'] ?? [];
                    $intersectionKinds = null;
                    if (array_key_exists(ScalarReferenceIntersection::FIELD, $rule)) {
                        $intersectionKinds = ScalarReferenceIntersection::kinds(
                            $rule,
                            $path . ' options.' . $name
                        );
                    }
                    if (!empty($rule['sub_keys']) && is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            $subRule = (array) ($rule['sub_keys'][$subKey] ?? []);
                            if (($subRule['class'] ?? '') !== 'authored') {
                                continue; // RepositoryAuthorization reports ownership violations.
                            }
                            $subLocator = 'options.' . $name . '.' . $subKey;
                            if (!empty($subRule['json_refs']) || !empty($subRule['key_refs'])) {
                                $this->validate_structured_rule($subValue, $subRule, $path, $subLocator);
                            } elseif (!empty($subRule['ref'])) {
                                $this->validate_declared_ref(
                                    $subValue,
                                    (string) $subRule['ref'],
                                    $path,
                                    $subLocator
                                );
                            }
                        }
                    } elseif (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'options.' . $name);
                    } elseif (!empty($rule['ref'])) {
                        $locator = 'options.' . $name;
                        if ($intersectionKinds !== null) {
                            $this->validate_scalar_reference_intersection(
                                $value,
                                $intersectionKinds[0],
                                (string) $rule[ScalarReferenceIntersection::TAXONOMY_FIELD],
                                $termTargets,
                                $path,
                                $locator
                            );
                        } else {
                            $this->validate_declared_ref(
                                $value,
                                (string) $rule['ref'],
                                $path,
                                $locator,
                                true
                            );
                        }
                    }
                }
            } elseif ($entity['type'] === 'user-meta') {
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_user((string) $key, $meta) ?? [];
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
                }
            } elseif ($entity['type'] === SidebarState::ENTITY_TYPE) {
                $declaredWidgets = $this->policy->widget_types();
                foreach ((array) ($d['widgets'] ?? []) as $i => $widget) {
                    $type = (string) ($widget['type'] ?? '');
                    foreach ((array) ($widget['settings'] ?? []) as $setting => $value) {
                        $rule = (array) (($declaredWidgets[$type]['settings'] ?? [])[$setting] ?? []);
                        if (!empty($rule['ref'])) {
                            $this->validate_declared_ref(
                                $value,
                                (string) $rule['ref'],
                                $path,
                                "widgets[$i].settings.$setting"
                            );
                        }
                        if (($rule['codec'] ?? '') === 'blocks' && !is_string($value)) {
                            $this->add(
                                'schema_content_mismatch', $path, "widgets[$i].settings.$setting",
                                'block-content widget setting must be a string'
                            );
                        } elseif (($rule['codec'] ?? '') === 'blocks') {
                            $this->validate_block_values($value, $path, "widgets[$i].settings.$setting");
                        }
                    }
                }
            } elseif (isset($rows[$entity['type']])) {
                foreach ($rows[$entity['type']]['refs'] ?? [] as $ref) {
                    $column = (string) $ref['column'];
                    $this->validate_declared_ref(
                        $d['columns'][$column] ?? null, (string) $ref['kind'], $path, 'columns.' . $column
                    );
                }
                foreach ($metaByOwner[$entity['type']] ?? [] as $metaName => $decl) {
                    foreach ((array) ($d['meta'] ?? []) as $key => $value) {
                        $rule = ReferenceRules::attached_meta_key($decl, (string) $key);
                        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                            $this->validate_structured_rule(
                                $value,
                                $rule,
                                $path,
                                "meta:$metaName.$key"
                            );
                        } elseif (!empty($rule['ref'])) {
                            $this->validate_declared_ref(
                                $value, (string) $rule['ref'], $path, "meta:$metaName.$key"
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string,list<array{taxonomy:?string,path:string}>> $termTargets
     */
    private function validate_scalar_reference_intersection(
        $value,
        string $primaryKind,
        string $taxonomy,
        array $termTargets,
        string $path,
        string $locator
    ): void {
        if ($value === 0) {
            return; // the whole-option durable unset sentinel is canonical
        }
        if (!is_string($value)
            || preg_match(
                '/^\{\{' . preg_quote($primaryKind, '/') . ':(' . self::UUID_PATTERN . ')\}\}$/D',
                $value,
                $match
            ) !== 1) {
            $this->add(
                'nonportable_reference',
                $path,
                $locator,
                "declared $primaryKind reference must be a canonical token or integer zero, never a raw target id"
            );
            return;
        }
        $targets = $termTargets[$match[1]] ?? [];
        if (count($targets) === 1 && $targets[0]['taxonomy'] === $taxonomy) {
            return;
        }
        $this->add(
            'reference_taxonomy_mismatch',
            $path,
            $locator,
            "declared $primaryKind reference must resolve to exactly one canonical term in taxonomy '$taxonomy'",
            count($targets) === 1 ? $targets[0]['path'] : null
        );
    }

    /** Validate one canonical post/term meta value, including explicit repeated database rows. */
    private function validate_meta_value($value, array $rule, string $path, string $locator): void {
        if (!array_key_exists('repeated_rows', $rule)) {
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $this->validate_structured_rule($value, $rule, $path, $locator);
            } elseif (!empty($rule['ref'])) {
                $this->validate_declared_ref($value, (string) $rule['ref'], $path, $locator);
            }
            return;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $this->add(
                'schema_content_mismatch',
                $path,
                $locator,
                'repeated-row authored meta must be a non-empty canonical list'
            );
            return;
        }
        $seen = [];
        foreach ($value as $i => $one) {
            if (!is_scalar($one)) {
                $this->add(
                    'schema_content_mismatch',
                    $path,
                    $locator . "[$i]",
                    'each repeated authored meta row must contain one scalar canonical value'
                );
                continue;
            }
            if (is_string($one)) {
                try {
                    $decoded = PlainData::decode($one, "$path $locator[$i]");
                } catch (\RuntimeException) {
                    $this->add(
                        'schema_content_mismatch',
                        $path,
                        $locator . "[$i]",
                        'each repeated authored meta row must be one canonical decoded scalar value'
                    );
                    continue;
                }
                if ($decoded !== $one) {
                    $this->add(
                        'schema_content_mismatch',
                        $path,
                        $locator . "[$i]",
                        'each repeated authored meta row must be one canonical decoded scalar value'
                    );
                    continue;
                }
            }
            $fingerprint = "v\0" . serialize($one);
            if (isset($seen[$fingerprint])) {
                $this->add(
                    'schema_content_mismatch',
                    $path,
                    $locator . "[$i]",
                    'repeated-row authored meta values must be unique'
                );
            }
            $seen[$fingerprint] = true;
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $this->validate_structured_rule($one, $rule, $path, $locator . "[$i]");
            } elseif (!empty($rule['ref'])) {
                $this->validate_declared_ref($one, (string) $rule['ref'], $path, $locator . "[$i]");
            }
        }
    }

    /** Validate declared structural leaves and id-keyed maps before apply. */
    private function validate_structured_rule($value, array $rule, string $path, string $locator): void {
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            $copy = $value;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $ref['path']),
                function (&$container, $key, string $matchedLocator) use ($ref, $path, $locator): void {
                    if (!ReferenceCondition::matches($container, $ref, $locator . $matchedLocator)) return;
                    $leaf = $container[$key];
                    ReferenceCondition::assert_canonical($leaf, $ref, $locator . $matchedLocator);
                    if ($leaf === null || $leaf === '' || $leaf === 0 || $leaf === '0' || $leaf === false
                        || is_array($leaf)) {
                        return; // declared unset/container conventions
                    }
                    $this->validate_declared_ref(
                        $leaf,
                        (string) $ref['kind'],
                        $path,
                        $locator . $matchedLocator
                    );
                },
                ''
            );
        }
        $keyRefs = $rule['key_refs'] ?? null;
        if (!is_array($keyRefs)) {
            return;
        }
        $validateMap = function ($map, string $mapLocator) use ($keyRefs, $path, $locator): void {
            try {
                $map = StructuredReferenceCodec::key_ref_map($map, $keyRefs, "$path $locator$mapLocator");
            } catch (\RuntimeException) {
                $this->add('nonportable_reference', $path, $locator . $mapLocator,
                    'key_refs path must resolve to a valid typed container');
                return;
            }
            if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                $this->add(
                    'nonportable_reference',
                    $path,
                    $locator . $mapLocator,
                    'key_refs path must resolve to an id-keyed map'
                );
                return;
            }
            foreach ($map as $key => $_value) {
                $this->validate_declared_ref(
                    $key,
                    (string) $keyRefs['kind'],
                    $path,
                    $locator . $mapLocator . ' (key)'
                );
            }
        };
        if (isset($keyRefs['path'])) {
            $copy = $value;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $keyRefs['path']),
                function (&$container, $key, string $matchedLocator) use ($validateMap): void {
                    $validateMap($container[$key], $matchedLocator);
                },
                ''
            );
            return;
        }
        $validateMap($value, '');
    }

    private function validate_declared_ref(
        $value,
        string $ref,
        string $path,
        string $locator,
        bool $allowUnsetScalar = false
    ): void {
        if ($value === null) {
            return;
        }
        $many = str_ends_with($ref, '[]');
        $kind = $many ? substr($ref, 0, -2) : $ref;
        if ($allowUnsetScalar && !$many && $value === 0) {
            // sandbox/tests/live/regress_core_data_boundary.sh proves that
            // 0 is durable authored intent for WordPress's whole scalar
            // option refs; every other raw id remains environment-local.
            return;
        }
        if ($many) {
            if (!is_array($value) || !array_is_list($value)) {
                $this->add('nonportable_reference', $path, $locator, "declared $ref value must be a token list");
                return;
            }
            foreach ($value as $i => $one) {
                $this->validate_declared_ref($one, $kind, $path, $locator . "[$i]");
            }
            return;
        }
        $valid = $kind === 'user'
            ? is_string($value) && str_starts_with($value, 'user:') && strlen($value) > 5
            : is_string($value) && preg_match('/^\{\{' . preg_quote($kind, '/') . ':[0-9a-f-]{36}\}\}$/', $value);
        if (!$valid) {
            $this->add('nonportable_reference', $path, $locator, "declared $kind reference must be a canonical token, never a raw target id");
        }
    }

    private function validate_json_body(string $body, string $postType, string $path): void {
        try {
            $decoded = BodyRefGrammar::decode($body, "$path body");
        } catch (\Throwable $_invalid) {
            $this->add('schema_content_mismatch', $path, 'body',
                'json post body must reproduce its exact bytes through the declared JSON codec');
            return;
        }
        $rule = $this->policy->body_ref_rule($postType);
        if ($rule === null) {
            $this->add('schema_content_mismatch', $path, 'body', 'json post body has no declared reference rule');
            return;
        }
        // Lint deliberately skips containers, but a compiler cannot: the
        // old JSON arm admitted raw native IDs, wrong keyspaces and containers
        // at declared paths while meta references already refused them here.
        // Grammar owns paths, sentinels and unset conventions; this pass owns
        // canonical token shape. Neither needs a plugin-specific JSON walker.
        foreach (BodyRefGrammar::reference_positions($decoded, $rule, true) as $position) {
            if (($position['ref']['cast'] ?? null) === 'preserve') {
                try {
                    IdentityTokenCodec::decode_typed($position['value'], (string) $position['ref']['kind']);
                } catch (\RuntimeException) {
                    $this->add('nonportable_reference', $path, 'body' . $position['locator'],
                        'type-preserving body reference must be a canonical typed-reference envelope');
                }
                continue;
            }
            $this->validate_declared_ref($position['value'], (string) $position['ref']['kind'],
                $path, 'body' . $position['locator']);
        }
    }

    private function validate_serialized_body(string $body, string $path): void {
        try {
            $decoded = PlainData::decode_serialized($body, "$path body");
        } catch (\Throwable $_invalid) {
            $this->add(
                'schema_content_mismatch',
                $path,
                'body',
                'serialized post body must be canonical, bounded, class-free PHP plain data'
            );
            return;
        }
        $secret = Secrets::clearance_match_deep('body', $decoded);
        if ($secret !== null) {
            $this->add(
                'repository_serialized_body_secret_not_allowed',
                $path,
                'body',
                "serialized authored configuration contains a $secret"
            );
        }
        $pii = PersonalData::match_deep('body', $decoded);
        if ($pii !== null) {
            $this->add(
                'repository_serialized_body_pii_not_allowed',
                $path,
                'body',
                "serialized authored configuration contains $pii"
            );
        }
    }

    private function validate_block_values(string $body, string $path, string $rootLocator = 'body'): void {
        $rules = [];
        foreach ($this->policy->block_attr_rules() as $block => $attributes) {
            foreach ($attributes as $rule) {
                if (isset($rule['value'])) $rules[$block][$rule['path']] = $rule['value'];
            }
        }
        try {
            $blocks = BlockAttributeReader::read($body, array_keys($rules));
        } catch (\RuntimeException $e) {
            $this->add('repository_block_value_invalid', $path, $rootLocator, $e->getMessage());
            return;
        }
        foreach ($blocks as $block) {
            foreach ($rules[$block['blockName']] as $attribute => $rule) {
                if (!array_key_exists($attribute, $block['attrs'])) continue;
                $locator = $rootLocator . '@' . $block['offset'] . '.attrs.' . $attribute;
                try {
                    BlockValueCodec::assert_value($block['attrs'][$attribute], $rule, true, $locator);
                } catch (\RuntimeException $e) {
                    $this->add('repository_block_value_invalid', $path, $locator, $e->getMessage());
                }
            }
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
