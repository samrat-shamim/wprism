<?php
namespace WPrism;

/**
 * Policy-aware identity pruning for authored typed tables (issue #3349).
 *
 * This boundary owns the option-name preservation witness and full-capture
 * map pruning. Lifecycle observation reuses the witness without pruning. It does
 * not load Snapshot, Policy, Ledger, OptionState, or WordPress bootstrap code:
 * the compatibility facade injects those runtime capabilities explicitly.
 */
final class SnapshotPruner {
    private object $policy;
    private \Closure $optionRecords;
    private \Closure $ledgerIdFor;
    private \Closure $strictPositiveLocalId;
    private \Closure $pruneDeadTableMap;
    private \Closure $pruneDeadCompositeTableMap;
    private \Closure $isCompositeRef;

    public function __construct(
        object $policy,
        \Closure $optionRecords,
        \Closure $ledgerIdFor,
        \Closure $strictPositiveLocalId,
        \Closure $pruneDeadTableMap,
        \Closure $pruneDeadCompositeTableMap,
        \Closure $isCompositeRef
    ) {
        $this->policy = $policy;
        $this->optionRecords = $optionRecords;
        $this->ledgerIdFor = $ledgerIdFor;
        $this->strictPositiveLocalId = $strictPositiveLocalId;
        $this->pruneDeadTableMap = $pruneDeadTableMap;
        $this->pruneDeadCompositeTableMap = $pruneDeadCompositeTableMap;
        $this->isCompositeRef = $isCompositeRef;
    }

    /**
     * Preserve typed-row identities which an authored option-name namespace
     * still needs. A typed row can disappear before its paired option does;
     * pruning that mapping would make the option look unrelated before
     * capture can emit the two canonical tombstones together.
     *
     * The live option scan covers that recovery case. The frozen options
     * document additionally covers a previous canonical deletion record when
     * the option is already absent on this target. This is identity
     * preservation only: it never mints or rebinds a mapping and never widens
     * option ownership.
     *
     * @param null|list<string> $liveOptionNames A caller's already bounded, same-snapshot namespace observation; null keeps the maintenance scan.
     * @param null|\Closure(\Closure():void):void $observeCanonicalName Execute one complete canonical-name witness within the caller's observation boundary.
     * @return array<string,int[]> id_kind => local ids excluded from pruning
     */
    public function option_name_ref_preserved_ids(
        ?array $repositoryOptions = null,
        ?array $liveOptionNames = null,
        ?\Closure $observeCanonicalName = null
    ): array {
        if ($liveOptionNames !== null && (!array_is_list($liveOptionNames)
            || count(array_filter($liveOptionNames, 'is_string')) !== count($liveOptionNames))) {
            throw new \LogicException('wprism: option-name identity observation requires a list of names');
        }
        $rulesByKind = [];
        foreach ($this->policy->option_name_ref_rules() as $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $kind = (string) ($rule['id_kind'] ?? '');
            if ($kind !== '') {
                $rulesByKind[$kind][] = $rule;
            }
        }
        if (!$rulesByKind) {
            return [];
        }

        $preserve = [];
        global $wpdb;
        $optionsTable = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($wpdb->options ?? (($wpdb->prefix ?? 'wp_') . 'options'))
        );
        if ($liveOptionNames !== null || $optionsTable !== '') {
            // A failed option scan is not the same thing as an empty option
            // table. Fail closed before the following dead-map DELETE.
            $live = $liveOptionNames;
            if ($live === null) {
                $wpdb->last_error = '';
                $live = $wpdb->get_results("SELECT `option_name` FROM `$optionsTable`", ARRAY_A);
                if ($live === false || $live === null || !empty($wpdb->last_error)) {
                    throw new \RuntimeException(
                        'wprism: cannot reconcile option_name_refs identities because the wp_options scan failed'
                    );
                }
            }
            foreach ($live as $row) {
                $name = $liveOptionNames === null ? (string) ($row['option_name'] ?? '') : $row;
                $details = $this->policy->option_name_ref_match_details($name);
                if ($details === null || ($details['rule']['class'] ?? '') !== 'authored') {
                    continue;
                }
                $kind = (string) ($details['rule']['id_kind'] ?? '');
                if (!isset($rulesByKind[$kind])) {
                    continue;
                }
                $matches = $details['matches'] ?? [];
                $rawId = $matches['id'][0] ?? null;
                $id = ($this->strictPositiveLocalId)($rawId);
                if ($id === null) {
                    throw new \RuntimeException(
                        "wprism: option '$name' has an invalid local id in option_name_refs; refusing identity pruning"
                    );
                }
                $preserve[$kind][] = $id;
            }
        }

        // A previous canonical deletion can be the only remaining witness
        // after the live option row has already gone.
        if ($repositoryOptions !== null) {
            foreach (array_keys(($this->optionRecords)($repositoryOptions)) as $name) {
                $observe = function () use ($name, $rulesByKind, &$preserve): void {
                    $matched = preg_match_all(
                        '/\{\{([a-z][a-z0-9_]*):([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}/',
                        (string) $name,
                        $matches,
                        PREG_SET_ORDER
                    );
                    if ($matched === false || $matched === 0) {
                        return;
                    }
                    foreach ($matches as $match) {
                        $kind = (string) ($match[1] ?? '');
                        if (!isset($rulesByKind[$kind])) {
                            continue;
                        }
                        $id = ($this->ledgerIdFor)((string) ($match[2] ?? ''), $kind);
                        if ($id === null || $id <= 0) {
                            continue;
                        }

                        // Canonical names are untrusted repository input until
                        // exact authored ownership is reconstructed and proved.
                        $tokenText = (string) ($match[0] ?? '');
                        $replacementCount = 0;
                        $numericName = preg_replace(
                            '/' . preg_quote($tokenText, '/') . '/',
                            (string) $id,
                            (string) $name,
                            1,
                            $replacementCount
                        );
                        if ($numericName === null || $replacementCount !== 1) {
                            throw new \RuntimeException(
                                "wprism: canonical option token for id_kind '$kind' could not be reconstructed safely"
                            );
                        }
                        $details = $this->policy->option_name_ref_match_details($numericName);
                        if ($details === null
                            || ($details['rule']['class'] ?? '') !== 'authored'
                            || (string) ($details['rule']['id_kind'] ?? '') !== $kind
                            || ($this->strictPositiveLocalId)($details['matches']['id'][0] ?? null) !== $id) {
                            throw new \RuntimeException(
                                "wprism: canonical option token for id_kind '$kind' is not owned by exactly one authored option_name_refs rule"
                            );
                        }
                        $preserve[$kind][] = $id;
                    }
                };
                if ($observeCanonicalName === null) {
                    $observe();
                } else {
                    $observeCanonicalName($observe);
                }
            }
        }

        foreach ($preserve as $kind => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids), SORT_NUMERIC));
            sort($ids, SORT_NUMERIC);
            $preserve[$kind] = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        }
        return $preserve;
    }

    /**
     * Full-capture pruning for every declared row table. Ordinary mapped and
     * natural-key rows use their scalar PK; composite_ref rows use their two
     * identity columns so lifecycle removal/recreation cannot strand a packed
     * tuple binding from the pre-removal target ids.
     *
     * @param array<string,array> $rowTables validated row-table roster
     */
    public function prune_dead_map(array $rowTables, ?array $repositoryOptions = null): void {
        $tables = [];
        $compositeTables = [];
        foreach ($rowTables as $name => $decl) {
            if (($this->isCompositeRef)($decl)) {
                $compositeTables[$decl['id_kind']] = [
                    'table' => $name,
                    'columns' => array_values((array) ($decl['identity']['columns'] ?? [])),
                ];
                continue;
            }
            $tables[$decl['id_kind']] = ['table' => $name, 'pk' => $decl['pk']];
        }
        if ($tables) {
            ($this->pruneDeadTableMap)(
                $tables,
                $this->option_name_ref_preserved_ids($repositoryOptions)
            );
        }
        if ($compositeTables) {
            ($this->pruneDeadCompositeTableMap)($compositeTables);
        }
    }

}
