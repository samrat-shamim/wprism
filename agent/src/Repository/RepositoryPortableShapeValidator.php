<?php
namespace Duo;

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
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/SidebarState.php';
}
if (!class_exists(ReferenceRules::class, false)) {
    require_once __DIR__ . '/../Kernel/ReferenceRules.php';
}
if (!class_exists(JsonRefs::class, false)) {
    require_once __DIR__ . '/../Kernel/JsonRefs.php';
}

final class RepositoryPortableShapeValidator {
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
        foreach ($tree as $entity) {
            $path = $entity['path'];
            $d = $entity['data'];
            if ($entity['type'] === 'post') {
                if (($d['author'] ?? null) !== null
                    && (!is_string($d['author']) || !str_starts_with($d['author'], 'user:'))) {
                    $this->add('nonportable_reference', $path, 'author', 'post author must be a user:<login> token');
                }
                if (($d['parent'] ?? null) !== null) {
                    $this->validate_declared_ref($d['parent'], 'post', $path, 'parent');
                }
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_post((string) $key, $meta) ?? [];
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
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
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
                }
            } elseif ($entity['type'] === 'options') {
                // DUO-3263: an interpreter-classified option's ref kind (ACF's
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
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'options.' . $name);
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

    /** Validate declared structural leaves and id-keyed maps before apply. */
    private function validate_structured_rule($value, array $rule, string $path, string $locator): void {
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            $copy = $value;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $ref['path']),
                function (&$container, $key, string $matchedLocator) use ($ref, $path, $locator): void {
                    $leaf = $container[$key];
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

    private function validate_declared_ref($value, string $ref, string $path, string $locator): void {
        if ($value === null) {
            return;
        }
        $many = str_ends_with($ref, '[]');
        $kind = $many ? substr($ref, 0, -2) : $ref;
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
            ? is_string($value) && str_starts_with($value, 'user:')
            : is_string($value) && preg_match('/^\{\{' . preg_quote($kind, '/') . ':[0-9a-f-]{36}\}\}$/', $value);
        if (!$valid) {
            $this->add('nonportable_reference', $path, $locator, "declared $kind reference must be a canonical token, never a raw target id");
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
